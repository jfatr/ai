<?php

namespace Laravel\Ai\Gateway\Cli;

use Laravel\Ai\Contracts\Providers\TextProvider;

class ClaudeCodeGateway extends CliGateway
{
    /**
     * {@inheritdoc}
     */
    protected function label(): string
    {
        return 'Claude Code';
    }

    /**
     * {@inheritdoc}
     */
    protected function command(TextProvider $provider, string $model, ?string $instructions, ?array $schema, string $directory): array
    {
        $config = $provider->additionalConfiguration();

        return [
            $config['binary'] ?? 'claude',
            '--print',
            '--output-format', 'stream-json',
            '--verbose',
            '--include-partial-messages',
            // The CLI answers as a plain model: no built-in tools, settings, MCP servers, or saved session...
            '--tools', '',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--disable-slash-commands',
            '--no-session-persistence',
            ...($model === self::DEFAULT_MODEL ? [] : ['--model', $model]),
            ...(filled($instructions) ? ['--system-prompt', $instructions] : []),
            ...($schema === null ? [] : ['--json-schema', json_encode($schema, JSON_THROW_ON_ERROR)]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function input(?string $instructions, string $prompt): string
    {
        return $prompt;
    }

    /**
     * {@inheritdoc}
     */
    protected function environment(TextProvider $provider): array
    {
        $config = $provider->additionalConfiguration();

        return array_filter([
            // An inherited API key would be billed in place of the subscription...
            'ANTHROPIC_API_KEY' => false,
            'ANTHROPIC_AUTH_TOKEN' => false,
            'CLAUDE_CODE_OAUTH_TOKEN' => $config['oauth_token'] ?? null,
            'CLAUDE_CONFIG_DIR' => $config['home'] ?? null,
        ], fn (mixed $value): bool => $value === false || filled($value));
    }

    /**
     * {@inheritdoc}
     */
    protected function interpret(array $event, CliTurn $turn): ?string
    {
        $type = $event['type'] ?? null;

        if ($type === 'stream_event') {
            $turn->model ??= $event['event']['message']['model'] ?? null;

            return ($event['event']['delta']['type'] ?? null) === 'text_delta'
                ? ($event['event']['delta']['text'] ?? null)
                : null;
        }

        if ($type !== 'result') {
            return null;
        }

        $result = is_string($event['result'] ?? null) ? $event['result'] : null;

        if ($event['is_error'] ?? false) {
            $turn->error = $result ?? ($event['subtype'] ?? 'unknown error');

            return null;
        }

        $usage = $event['usage'] ?? [];
        $cacheRead = $usage['cache_read_input_tokens'] ?? null;
        $cacheWrite = $usage['cache_creation_input_tokens'] ?? null;

        $turn->text = $result;
        $turn->structured = is_array($event['structured_output'] ?? null) ? $event['structured_output'] : null;
        $turn->inputTokens = ($usage['input_tokens'] ?? 0) + ($cacheRead ?? 0) + ($cacheWrite ?? 0);
        $turn->outputTokens = $usage['output_tokens'] ?? 0;
        $turn->cacheReadInputTokens = $cacheRead;
        $turn->cacheWriteInputTokens = $cacheWrite;

        return null;
    }
}
