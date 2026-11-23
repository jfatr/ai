<?php

namespace Laravel\Ai\Gateway\Cli;

use Generator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

use function Laravel\Ai\ulid;

/**
 * Generates text by running a vendor's own command line agent, which authenticates
 * with whatever account it is signed in to, in place of calling an HTTP API.
 *
 * The CLI runs its own tool loop, so the agent's tools are served to it over MCP
 * and run while the turn is in progress rather than between steps.
 */
abstract class CliGateway implements StepTextGateway
{
    use DecodesStructuredOutput;
    use InvokesTools;

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
     * @return array<int, string>
     */
    abstract protected function command(CliRequest $request): array;

    /**
     * Build the text written to the CLI's standard input.
     */
    abstract protected function input(CliRequest $request): string;

    /**
     * Get the environment variables the CLI runs with, where false removes an inherited variable.
     *
     * @return array<string, string|false>
     */
    abstract protected function environment(CliRequest $request): array;

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
        $run = $this->run($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);

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

        $run = $this->run($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
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
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $tools = $this->flattenTools($tools);
        $files = new Filesystem;

        // The CLI runs in an empty directory so it has no project to read or act on...
        $files->ensureDirectoryExists($directory = sys_get_temp_dir().'/lai-'.bin2hex(random_bytes(6)), 0700);

        $request = new CliRequest(
            $provider,
            $model,
            $instructions,
            $this->prompt($messages),
            filled($schema) ? (new ObjectSchema($schema))->toSchema() : null,
            $this->attachments($messages),
            $options,
            $directory,
        );

        $turn = new CliTurn;
        $calls = [];
        $bridge = null;
        $process = null;

        try {
            if ($tools !== []) {
                $bridge = new ToolBridge($directory, $this->toolDefinitions($tools), function (string $name, array $arguments) use ($tools, &$calls): string {
                    $result = $this->callTool($name, $arguments, $tools);

                    $calls[] = new ProviderToolCall(ulid(), 'mcp_tool_call', [
                        'name' => $name,
                        'arguments' => $arguments,
                        'result' => $result,
                    ]);

                    return $result;
                });

                $bridge->start();

                $request->toolServer = $bridge->command($request->config('php_binary'));
            }

            $process = Process::path($directory)
                ->env($this->environment($request))
                ->timeout($timeout ?? 300)
                ->input($this->input($request))
                ->start($this->command($request));

            $buffer = '';
            $stopAtLimit = $options?->maxTokens !== null;

            while ($process->running()) {
                $buffer .= $process->latestOutput();

                yield from $this->consume($buffer, $turn, $stopAtLimit);

                // The CLI would carry on past the limit by itself, so the turn ends here...
                if ($stopAtLimit && $turn->truncated) {
                    break;
                }

                $bridge?->serve();

                Sleep::for(20)->milliseconds();
            }

            if (! ($stopAtLimit && $turn->truncated)) {
                $result = $process->wait();

                $buffer .= $process->latestOutput()."\n";

                yield from $this->consume($buffer, $turn, $stopAtLimit);

                if ($turn->error !== null || $result->failed()) {
                    throw new AiException(sprintf(
                        '%s error: %s',
                        $this->label(),
                        $turn->error ?? (trim($result->errorOutput()) ?: 'the process exited with code '.$result->exitCode().'.'),
                    ));
                }
            }
        } finally {
            if ($process?->running()) {
                $process->stop(2);
            }

            $bridge?->close();
            $files->deleteDirectory($directory);
        }

        $truncated = $stopAtLimit && $turn->truncated;
        $text = $truncated ? $turn->streamed : ($turn->text ?? $turn->streamed);

        // A CLI that only reports its answer at the end still streams it as a single chunk...
        if ($turn->streamed === '' && $text !== '') {
            yield $text;
        }

        return new StepResponse(
            $text,
            [],
            $truncated ? FinishReason::Length : FinishReason::Stop,
            new TextUsage(
                $turn->inputTokens,
                $turn->outputTokens,
                $turn->cacheReadInputTokens,
                $turn->cacheWriteInputTokens,
                $turn->reasoningTokens,
            ),
            new Meta($provider->name(), $turn->model ?? $model),
            $request->schema === null || $truncated ? null : ($turn->structured ?? $this->decodeStructuredOutput($text)),
            providerToolCalls: $calls,
        );
    }

    /**
     * Interpret each complete line in the buffer, leaving any partial line behind.
     *
     * When stopping at the output limit, nothing past the point it was reached is read.
     *
     * @return Generator<int, string>
     */
    protected function consume(string &$buffer, CliTurn $turn, bool $stopAtLimit = false): Generator
    {
        // Anything after the limit is the CLI carrying on by itself, which is not part of the answer...
        while (! ($stopAtLimit && $turn->truncated) && ($position = strpos($buffer, "\n")) !== false) {
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

    /**
     * Gather the attachments of every user message, as a CLI turn carries a single prompt.
     *
     * @param  array<int, mixed>  $messages
     * @return Collection<int, mixed>
     */
    protected function attachments(array $messages): Collection
    {
        return (new Collection($messages))
            ->flatMap(fn (mixed $message): array => $message instanceof UserMessage ? $message->attachments->all() : [])
            ->values();
    }

    /**
     * Get the agent's tools as a flat list, rejecting the kinds a CLI cannot be given.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, Tool>
     */
    protected function flattenTools(array $tools): array
    {
        $flattened = [];

        foreach ($tools as $tool) {
            match (true) {
                $tool instanceof ToolSearch => array_push($flattened, ...$this->flattenTools($tool->tools)),
                $tool instanceof Tool => $flattened[] = $tool,
                default => throw new AiException(sprintf(
                    'The %s provider does not support the [%s] tool.', $this->label(), get_debug_type($tool),
                )),
            };
        }

        return $flattened;
    }

    /**
     * Describe the tools as MCP tool definitions.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function toolDefinitions(array $tools): array
    {
        return array_map(function (Tool $tool): array {
            $schema = $tool->schema(new JsonSchemaTypeFactory);
            $schema = filled($schema) ? (new ObjectSchema($schema))->toSchema() : [];

            return [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string) $tool->description(),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) ($schema['properties'] ?? []),
                    'required' => $schema['required'] ?? [],
                ],
            ];
        }, $tools);
    }

    /**
     * Run the named tool for the CLI, returning what the model should be told.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, Tool>  $tools
     */
    protected function callTool(string $name, array $arguments, array $tools): string
    {
        $tool = $this->findTool($name, $tools);

        if ($tool === null) {
            return "The tool [{$name}] does not exist.";
        }

        // A running CLI turn cannot be paused for a person to decide, so a gated call is refused...
        if ($tool instanceof Approvable && $tool->shouldRequestApproval(new Request($arguments)) !== null) {
            return "The tool [{$name}] requires approval, which the {$this->label()} provider does not support. It was not run.";
        }

        return $this->executeTool($tool, $arguments);
    }
}
