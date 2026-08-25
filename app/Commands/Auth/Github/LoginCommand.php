<?php

namespace App\Commands\Auth\Github;

use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\Hosts\GithubClient;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\password;

class LoginCommand extends Command
{
    protected $signature = 'auth:github:login';

    protected $description = 'Save a GitHub personal access token';

    public function handle(AuthService $authService): int
    {
        $token = password(
            label: 'GitHub personal access token',
            required: true,
        );

        try {
            $username = (new GithubClient($token))->currentUsername();
        } catch (Throwable $e) {
            $this->components->error("Could not authenticate with GitHub: {$e->getMessage()}");

            return self::FAILURE;
        }

        $authService->saveHost(GitHost::Github, ['token' => $token]);

        $this->components->info("Authenticated as {$username}. Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }
}
