<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Laravel\Ai\Contracts\TokenStore;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ai:logout')]
#[Description('Remove the stored OAuth token of an AI provider')]
class LogoutCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'ai:logout {provider : The name of the provider in config/ai.php}';

    /**
     * Execute the console command.
     */
    public function handle(TokenStore $tokens): int
    {
        $tokens->forget($provider = (string) $this->argument('provider'));

        $this->components->info("Signed out of the [{$provider}] provider.");

        return self::SUCCESS;
    }
}
