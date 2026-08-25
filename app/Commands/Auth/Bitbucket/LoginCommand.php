<?php

namespace App\Commands\Auth\Bitbucket;

use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\Hosts\BitbucketClient;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class LoginCommand extends Command
{
    protected $signature = 'auth:bitbucket:login';

    protected $description = 'Save Bitbucket credentials (account email and API token)';

    public function handle(AuthService $authService): int
    {
        $username = text(
            label: 'Bitbucket account email',
            placeholder: 'your-email@example.com',
            required: true,
        );

        $appPassword = password(
            label: 'Bitbucket API token',
            required: true,
        );

        try {
            $displayName = (new BitbucketClient($username, $appPassword))->currentUsername();
        } catch (Throwable $e) {
            $this->components->error("Could not authenticate with Bitbucket: {$e->getMessage()}");

            return self::FAILURE;
        }

        $authService->saveHost(GitHost::Bitbucket, ['username' => $username, 'app_password' => $appPassword]);

        $this->components->info("Authenticated as {$displayName}. Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }
}
