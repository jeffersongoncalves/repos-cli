<?php

namespace App\Commands\Auth\Gitlab;

use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\Hosts\GitlabClient;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\password;

class LoginCommand extends Command
{
    protected $signature = 'auth:gitlab:login';

    protected $description = 'Save a GitLab personal access token';

    public function handle(AuthService $authService): int
    {
        $token = password(
            label: 'GitLab personal access token',
            required: true,
        );

        try {
            $username = (new GitlabClient($token))->currentUsername();
        } catch (Throwable $e) {
            $this->components->error("Could not authenticate with GitLab: {$e->getMessage()}");

            return self::FAILURE;
        }

        $authService->saveHost(GitHost::Gitlab, ['token' => $token]);

        $this->components->info("Authenticated as {$username}. Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }
}
