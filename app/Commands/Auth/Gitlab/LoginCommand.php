<?php

namespace App\Commands\Auth\Gitlab;

use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\Hosts\GitlabClient;
use App\Services\Hosts\GitlabDeviceAuth;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\password;

class LoginCommand extends Command
{
    protected $signature = 'auth:gitlab:login
        {--device : Authenticate via GitLab device flow instead of a personal access token}';

    protected $description = 'Save a GitLab personal access token';

    public function handle(AuthService $authService): int
    {
        return $this->option('device')
            ? $this->handleDeviceFlow($authService)
            : $this->handleTokenFlow($authService);
    }

    protected function handleTokenFlow(AuthService $authService): int
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

    protected function handleDeviceFlow(AuthService $authService): int
    {
        $auth = new GitlabDeviceAuth;

        try {
            $device = $auth->requestDeviceCode();

            $this->components->info("Open {$device['verification_uri']} and enter code: {$device['user_code']}");

            $token = $auth->pollForToken($device['device_code'], $device['interval']);

            $username = (new GitlabClient($token['access_token']))->currentUsername();
        } catch (Throwable $e) {
            $this->components->error("Could not authenticate with GitLab: {$e->getMessage()}");

            return self::FAILURE;
        }

        $authService->saveHost(GitHost::Gitlab, [
            'token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires_at' => isset($token['expires_in']) ? (string) (time() + (int) $token['expires_in']) : '',
        ]);

        $this->components->info("Authenticated as {$username}. Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }
}
