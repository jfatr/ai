<?php

namespace Laravel\Ai\Gateway\Cli;

use Laravel\Ai\Gateway\Anthropic\Concerns\MapsAttachments;

class ClaudeCodeGateway extends CliGateway
{
    use MapsAttachments;

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
    protected function command(CliRequest $request): array
    {
        return [
            $request->config('binary', 'claude'),
            '--print',
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--verbose',
            '--include-partial-messages',
            // The CLI answers as a plain model: no built-in tools, settings, MCP servers, or saved session...
            '--tools', '',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--disable-slash-commands',
            '--no-session-persistence',
            ...($request->model === self::DEFAULT_MODEL ? [] : ['--model', $request->model]),
            ...(filled($request->instructions) ? ['--system-prompt', $request->instructions] : []),
            ...($request->schema === null ? [] : ['--json-schema', json_encode($request->schema, JSON_THROW_ON_ERROR)]),
            ...($request->toolServer === null ? [] : [
                '--mcp-config', json_encode(['mcpServers' => [ToolBridge::SERVER => [
                    'command' => $request->toolServer[0],
                    'args' => array_slice($request->toolServer, 1),
                ]]], JSON_THROW_ON_ERROR),
                '--allowedTools', 'mcp__'.ToolBridge::SERVER,
            ]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function input(CliRequest $request): string
    {
        return json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [
            ...($request->prompt === '' ? [] : [['type' => 'text', 'text' => $request->prompt]]),
            ...$this->mapAttachments($request->attachments),
        ]]], JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * {@inheritdoc}
     */
    protected function environment(CliRequest $request): array
    {
        return array_filter([
            // An inherited API key would be billed in place of the subscription...
            'ANTHROPIC_API_KEY' => false,
            'ANTHROPIC_AUTH_TOKEN' => false,
            'CLAUDE_CODE_OAUTH_TOKEN' => $request->config('oauth_token'),
            'CLAUDE_CONFIG_DIR' => $request->config('home'),
            'CLAUDE_CODE_MAX_OUTPUT_TOKENS' => $request->options?->maxTokens === null
                ? null
                : (string) $request->options->maxTokens,
        ], fn (mixed $value): bool => $value === false || filled($value));
    }

    /**
     * {@inheritdoc}
     */
    protected function interpret(array $event, CliTurn $turn): ?string
    {
        $type = $event['type'] ?? null;

        if ($type === 'stream_event') {
            $stream = $event['event'] ?? [];

            $turn->model ??= $stream['message']['model'] ?? null;

            if (($stream['delta']['stop_reason'] ?? null) === 'max_tokens') {
                $turn->truncated = true;

                $this->recordUsage($stream['usage'] ?? [], $turn);
            }

            return ($stream['delta']['type'] ?? null) === 'text_delta'
                ? ($stream['delta']['text'] ?? null)
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

        $turn->text = $result;
        $turn->structured = is_array($event['structured_output'] ?? null) ? $event['structured_output'] : null;

        $this->recordUsage($event['usage'] ?? [], $turn);

        return null;
    }

    /**
     * Record the token usage the CLI reported.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function recordUsage(array $usage, CliTurn $turn): void
    {
        $cacheRead = $usage['cache_read_input_tokens'] ?? null;
        $cacheWrite = $usage['cache_creation_input_tokens'] ?? null;

        $turn->inputTokens = ($usage['input_tokens'] ?? 0) + ($cacheRead ?? 0) + ($cacheWrite ?? 0);
        $turn->outputTokens = $usage['output_tokens'] ?? 0;
        $turn->cacheReadInputTokens = $cacheRead;
        $turn->cacheWriteInputTokens = $cacheWrite;
    }
}
