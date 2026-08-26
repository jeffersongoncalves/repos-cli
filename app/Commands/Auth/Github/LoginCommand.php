<?php

namespace App\Commands\Auth\Github;

use App\DTOs\Credentials;
use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\Hosts\GithubClient;
use App\Services\Hosts\GithubDeviceAuth;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\password;

class LoginCommand extends Command
{
    protected $signature = 'auth:github:login
        {--device : Authenticate via GitHub device flow instead of a personal access token}
        {--profile=default : Save under a named profile, to keep multiple GitHub credentials side by side}';

    protected $description = 'Save a GitHub personal access token';

    public function handle(AuthService $authService): int
    {
        $profile = (string) $this->option('profile') ?: Credentials::DEFAULT_PROFILE;

        return $this->option('device')
            ? $this->handleDeviceFlow($authService, $profile)
            : $this->handleTokenFlow($authService, $profile);
    }

    protected function handleTokenFlow(AuthService $authService, string $profile): int
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

        $authService->saveHost(GitHost::Github, ['token' => $token], $profile);

        $this->components->info("Authenticated as {$username} (profile '{$profile}'). Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }

    protected function handleDeviceFlow(AuthService $authService, string $profile): int
    {
        $auth = new GithubDeviceAuth;

        try {
            $device = $auth->requestDeviceCode();

            $this->components->info("Open {$device['verification_uri']} and enter code: {$device['user_code']}");

            $token = $auth->pollForToken($device['device_code'], $device['interval']);

            $username = (new GithubClient($token['access_token']))->currentUsername();
        } catch (Throwable $e) {
            $this->components->error("Could not authenticate with GitHub: {$e->getMessage()}");

            return self::FAILURE;
        }

        $authService->saveHost(GitHost::Github, [
            'token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires_at' => isset($token['expires_in']) ? (string) (time() + (int) $token['expires_in']) : '',
        ], $profile);

        $this->components->info("Authenticated as {$username} (profile '{$profile}'). Saved to {$authService->getConfigPath()}");

        return self::SUCCESS;
    }
}
