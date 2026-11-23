<?php

namespace Laravel\Ai\Gateway\Cli;

use Laravel\Ai\Contracts\Providers\TextProvider;

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
    protected function command(TextProvider $provider, string $model, ?string $instructions, ?array $schema, string $directory): array
    {
        $config = $provider->additionalConfiguration();

        if ($schema !== null) {
            file_put_contents($schemaPath = $directory.'/schema.json', json_encode($schema, JSON_THROW_ON_ERROR));
        }

        return [
            $config['binary'] ?? 'codex',
            'exec',
            '--json',
            '--skip-git-repo-check',
            '--ephemeral',
            '--sandbox', 'read-only',
            ...($model === self::DEFAULT_MODEL ? [] : ['--model', $model]),
            ...(isset($schemaPath) ? ['--output-schema', $schemaPath] : []),
            '-',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function input(?string $instructions, string $prompt): string
    {
        // Codex has no system prompt option, so the instructions lead the prompt...
        return filled($instructions)
            ? "<instructions>\n{$instructions}\n</instructions>\n\n{$prompt}"
            : $prompt;
    }

    /**
     * {@inheritdoc}
     */
    protected function environment(TextProvider $provider): array
    {
        return array_filter([
            'CODEX_HOME' => $provider->additionalConfiguration()['home'] ?? null,
        ]);
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
