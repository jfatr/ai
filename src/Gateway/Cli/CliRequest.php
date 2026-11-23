<?php

namespace Laravel\Ai\Gateway\Cli;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;

/**
 * Everything a CLI needs to run a single turn.
 */
class CliRequest
{
    /**
     * @param  array<string, mixed>|null  $schema  The JSON schema the answer must match.
     * @param  Collection<int, mixed>  $attachments
     * @param  array<int, string>|null  $toolServer  The command that starts the MCP server exposing the agent's tools.
     */
    public function __construct(
        public TextProvider $provider,
        public string $model,
        public ?string $instructions,
        public string $prompt,
        public ?array $schema,
        public Collection $attachments,
        public ?TextGenerationOptions $options,
        public string $directory,
        public ?array $toolServer = null,
    ) {}

    /**
     * Get a provider configuration value.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->provider->additionalConfiguration()[$key] ?? $default;
    }
}
