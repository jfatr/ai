<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Cli\ClaudeCodeGateway;

/**
 * Generates text through the Claude Code CLI, using the account that CLI is signed in to.
 */
class ClaudeCodeProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the credentials for the provider, which are held by the CLI itself.
     */
    #[\Override]
    public function providerCredentials(): array
    {
        return [];
    }

    /**
     * Get the provider connection configuration other than the driver, credentials, and name.
     */
    #[\Override]
    public function additionalConfiguration(): array
    {
        return array_diff_key($this->config, array_flip(['driver', 'name', 'ondemand']));
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new ClaudeCodeGateway;
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'sonnet';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'haiku';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'opus';
    }
}
