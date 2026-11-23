<?php

namespace Laravel\Ai\Gateway\Cli;

use Generator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

use function Laravel\Ai\ulid;

/**
 * Generates text by running a vendor's own command line agent, which authenticates
 * with whatever account it is signed in to, in place of calling an HTTP API.
 */
abstract class CliGateway implements StepTextGateway
{
    use DecodesStructuredOutput;

    /**
     * The sentinel model name that leaves the choice of model to the CLI.
     */
    public const DEFAULT_MODEL = 'default';

    /**
     * Get the human readable name of the CLI, used in error messages.
     */
    abstract protected function label(): string;

    /**
     * Build the command that runs a single non-interactive turn.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array<int, string>
     */
    abstract protected function command(TextProvider $provider, string $model, ?string $instructions, ?array $schema, string $directory): array;

    /**
     * Build the text written to the CLI's standard input.
     */
    abstract protected function input(?string $instructions, string $prompt): string;

    /**
     * Get the environment variables the CLI runs with, where false removes an inherited variable.
     *
     * @return array<string, string|false>
     */
    abstract protected function environment(TextProvider $provider): array;

    /**
     * Fold one event from the CLI's JSONL output into the turn, returning any new text.
     *
     * @param  array<string, mixed>  $event
     */
    abstract protected function interpret(array $event, CliTurn $turn): ?string;

    /**
     * {@inheritdoc}
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $run = $this->run($provider, $model, $instructions, $messages, $tools, $schema, $timeout);

        foreach ($run as $_) {
            //
        }

        return $run->getReturn();
    }

    /**
     * {@inheritdoc}
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        $run = $this->run($provider, $model, $instructions, $messages, $tools, $schema, $timeout);
        $started = false;

        foreach ($run as $delta) {
            if (! $started) {
                $started = true;

                yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);
            }

            yield (new TextDelta(ulid(), $messageId, $delta, time()))->withInvocationId($invocationId);
        }

        if ($started) {
            yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);
        }

        return $run->getReturn();
    }

    /**
     * Run the CLI for a single turn, yielding text as it arrives.
     *
     * @return Generator<int, string, mixed, StepResponse>
     */
    protected function run(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?int $timeout,
    ): Generator {
        if (filled($tools)) {
            throw new AiException("The {$this->label()} provider does not support tools.");
        }

        $prompt = $this->prompt($messages);
        $schema = filled($schema) ? (new ObjectSchema($schema))->toSchema() : null;

        $files = new Filesystem;

        // The CLI runs in an empty directory so it has no project to read or act on...
        $files->ensureDirectoryExists($directory = sys_get_temp_dir().'/laravel-ai-cli-'.bin2hex(random_bytes(8)), 0700);

        $turn = new CliTurn;

        try {
            $process = Process::path($directory)
                ->env($this->environment($provider))
                ->timeout($timeout ?? 300)
                ->input($this->input($instructions, $prompt))
                ->start($this->command($provider, $model, $instructions, $schema, $directory));

            $buffer = '';

            while ($process->running()) {
                $buffer .= $process->latestOutput();

                yield from $this->consume($buffer, $turn);

                Sleep::for(20)->milliseconds();
            }

            $result = $process->wait();

            $buffer .= $process->latestOutput()."\n";

            yield from $this->consume($buffer, $turn);
        } finally {
            $files->deleteDirectory($directory);
        }

        if ($turn->error !== null || $result->failed()) {
            throw new AiException(sprintf(
                '%s error: %s',
                $this->label(),
                $turn->error ?? (trim($result->errorOutput()) ?: 'the process exited with code '.$result->exitCode().'.'),
            ));
        }

        $text = $turn->text ?? $turn->streamed;

        // A CLI that only reports its answer at the end still streams it as a single chunk...
        if ($turn->streamed === '' && $text !== '') {
            yield $text;
        }

        return new StepResponse(
            $text,
            [],
            FinishReason::Stop,
            new TextUsage(
                $turn->inputTokens,
                $turn->outputTokens,
                $turn->cacheReadInputTokens,
                $turn->cacheWriteInputTokens,
                $turn->reasoningTokens,
            ),
            new Meta($provider->name(), $turn->model ?? $model),
            $schema === null ? null : ($turn->structured ?? $this->decodeStructuredOutput($text)),
        );
    }

    /**
     * Interpret each complete line in the buffer, leaving any partial line behind.
     *
     * @return Generator<int, string>
     */
    protected function consume(string &$buffer, CliTurn $turn): Generator
    {
        while (($position = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $position));
            $buffer = substr($buffer, $position + 1);

            $event = $line === '' ? null : json_decode($line, true);

            if (! is_array($event)) {
                continue;
            }

            $delta = $this->interpret($event, $turn);

            if ($delta !== null && $delta !== '') {
                $turn->streamed .= $delta;

                yield $delta;
            }
        }
    }

    /**
     * Render the conversation as the single prompt a CLI turn accepts.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function prompt(array $messages): string
    {
        $turns = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
                throw new AiException("The {$this->label()} provider does not support attachments.");
            }

            if ($message instanceof Message
                && in_array($message->role, [MessageRole::User, MessageRole::Assistant], true)
                && filled($message->content)) {
                $turns[] = [$message->role, $message->content];
            }
        }

        if (count($turns) === 1 && $turns[0][0] === MessageRole::User) {
            return $turns[0][1];
        }

        $transcript = implode("\n", array_map(
            fn (array $turn): string => "<{$turn[0]->value}>\n{$turn[1]}\n</{$turn[0]->value}>",
            $turns,
        ));

        return "This is the conversation so far:\n\n{$transcript}\n\nWrite the assistant's next reply to the last user message.";
    }
}
