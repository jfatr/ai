<?php

namespace Laravel\Ai\Gateway\Cli;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;

class CodexGateway extends CliGateway
{
    /**
     * {@inheritdoc}
     */
    protected function label(): string
    {
        return 'Codex';
    }

    /**
     * {@inheritdoc}
     */
    protected function command(CliRequest $request): array
    {
        if ($request->schema !== null) {
            file_put_contents($schema = $request->directory.'/schema.json', json_encode($request->schema, JSON_THROW_ON_ERROR));
        }

        $server = 'mcp_servers.'.ToolBridge::SERVER;

        // With no prompt argument, Codex reads the prompt from standard input...
        return [
            $request->config('binary', 'codex'),
            'exec',
            ...$this->images($request)->flatMap(fn (string $path): array => ['--image', $path])->all(),
            '--json',
            '--skip-git-repo-check',
            '--ephemeral',
            '--sandbox', 'read-only',
            ...($request->model === self::DEFAULT_MODEL ? [] : ['--model', $request->model]),
            ...(isset($schema) ? ['--output-schema', $schema] : []),
            ...($request->toolServer === null ? [] : [
                '--config', $server.'.command='.$this->toml($request->toolServer[0]),
                '--config', $server.'.args='.$this->toml(array_slice($request->toolServer, 1)),
                // The tools are the agent's own, so Codex runs them without asking...
                '--config', $server.'.default_tools_approval_mode="approve"',
            ]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function input(CliRequest $request): string
    {
        // Codex has no system prompt option, so the instructions lead the prompt...
        return filled($request->instructions)
            ? "<instructions>\n{$request->instructions}\n</instructions>\n\n{$request->prompt}"
            : $request->prompt;
    }

    /**
     * {@inheritdoc}
     */
    protected function environment(CliRequest $request): array
    {
        return array_filter(['CODEX_HOME' => $request->config('home')]);
    }

    /**
     * Get the paths of the attached images, writing those held in memory or on a disk to files.
     *
     * @return Collection<int, string>
     */
    protected function images(CliRequest $request): Collection
    {
        return $request->attachments->values()->map(function (mixed $attachment, int $index) use ($request): string {
            $write = function (string $contents, ?string $mime) use ($request, $index): string {
                $extension = match ($mime) {
                    'image/jpeg', 'image/jpg' => 'jpg',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'png',
                };

                file_put_contents($path = "{$request->directory}/image-{$index}.{$extension}", $contents);

                return $path;
            };

            return match (true) {
                $attachment instanceof LocalImage => $attachment->path,
                $attachment instanceof Base64Image => $write((string) base64_decode($attachment->base64), $attachment->mime),
                $attachment instanceof StoredImage => $write(
                    (string) Storage::disk($attachment->disk)->get($attachment->path), $attachment->mimeType(),
                ),
                $attachment instanceof UploadedFile && str_starts_with((string) $attachment->getClientMimeType(), 'image/') => $attachment->getRealPath(),
                default => throw new AiException(sprintf(
                    'The Codex provider only supports local, stored, uploaded, or base64 image attachments; [%s] given.',
                    get_debug_type($attachment),
                )),
            };
        });
    }

    /**
     * Encode a value as TOML for a Codex configuration override.
     *
     * @param  string|array<int, string>  $value
     */
    protected function toml(string|array $value): string
    {
        // A JSON string or array of strings is also valid TOML...
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * {@inheritdoc}
     */
    protected function interpret(array $event, CliTurn $turn): ?string
    {
        switch ($event['type'] ?? null) {
            case 'item.completed':
                if (($event['item']['type'] ?? null) !== 'agent_message' || ! is_string($event['item']['text'] ?? null)) {
                    return null;
                }

                // Only the last message is the answer; earlier ones are progress commentary...
                $turn->text = $event['item']['text'];

                return ($turn->streamed === '' ? '' : "\n\n").$event['item']['text'];

            case 'turn.completed':
                $usage = $event['usage'] ?? [];

                $turn->inputTokens = $usage['input_tokens'] ?? 0;
                $turn->outputTokens = $usage['output_tokens'] ?? 0;
                $turn->cacheReadInputTokens = $usage['cached_input_tokens'] ?? null;
                $turn->cacheWriteInputTokens = $usage['cache_write_input_tokens'] ?? null;
                $turn->reasoningTokens = $usage['reasoning_output_tokens'] ?? null;

                return null;

            case 'turn.failed':
                $turn->error = $event['error']['message'] ?? 'the turn failed.';

                return null;

            case 'error':
                $turn->error ??= $event['message'] ?? 'unknown error.';

                return null;
        }

        return null;
    }
}
