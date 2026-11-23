<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Laravel\Ai\Auth\DeviceFlow;
use Laravel\Ai\Contracts\TokenStore;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ai:login')]
#[Description('Sign in to an AI provider with an OAuth device code instead of an API key')]
class LoginCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'ai:login {provider : The name of the provider in config/ai.php}';

    /**
     * Execute the console command.
     */
    public function handle(DeviceFlow $flow, TokenStore $tokens): int
    {
        $provider = (string) $this->argument('provider');
        $oauth = config("ai.providers.{$provider}.oauth");

        if (! is_array($oauth) || blank($oauth['client_id'] ?? null)) {
            $this->components->error("The [{$provider}] provider has no [oauth.client_id] configured.");

            return self::FAILURE;
        }

        try {
            $device = $flow->start($oauth);

            $this->components->info('Open '.($device['verification_uri_complete'] ?? $device['verification_uri']).' and enter the code:');
            $this->line('  <options=bold>'.$device['user_code'].'</>');
            $this->newLine();
            $this->components->task('Waiting for approval', function () use ($flow, $tokens, $oauth, $device, $provider): void {
                $tokens->put($provider, $flow->poll($oauth, $device));
            });
        } catch (AiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Signed in. The [{$provider}] provider now authenticates with this token.");

        return self::SUCCESS;
    }
}
