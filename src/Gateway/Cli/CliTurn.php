<?php

namespace Laravel\Ai\Gateway\Cli;

/**
 * The state gathered from a CLI's event stream over a single turn.
 */
class CliTurn
{
    /**
     * The text streamed so far.
     */
    public string $streamed = '';

    /**
     * The final text reported by the CLI, when it reports one.
     */
    public ?string $text = null;

    /**
     * The structured output reported by the CLI, when it reports one.
     *
     * @var array<string, mixed>|null
     */
    public ?array $structured = null;

    /**
     * The error reported by the CLI.
     */
    public ?string $error = null;

    /**
     * The model reported by the CLI.
     */
    public ?string $model = null;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public ?int $cacheReadInputTokens = null;

    public ?int $cacheWriteInputTokens = null;

    public ?int $reasoningTokens = null;
}
