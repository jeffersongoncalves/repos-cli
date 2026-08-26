<?php

namespace App\Services;

use App\Contracts\HostClient;
use App\DTOs\Credentials;
use App\Enums\GitHost;
use App\Services\Hosts\AbstractDeviceAuth;
use App\Services\Hosts\BitbucketClient;
use App\Services\Hosts\GithubClient;
use App\Services\Hosts\GithubDeviceAuth;
use App\Services\Hosts\GitlabClient;
use App\Services\Hosts\GitlabDeviceAuth;
use JeffersonGoncalves\LaravelZero\Credentials\AuthenticationException;
use LogicException;

class HostClientFactory
{
    /**
     * Hosts that support the OAuth device flow, keyed to the client that can refresh
     * an expired access token for them.
     *
     * @var array<string, class-string<AbstractDeviceAuth>>
     */
    protected const DEVICE_AUTH_CLIENTS = [
        'github' => GithubDeviceAuth::class,
        'gitlab' => GitlabDeviceAuth::class,
    ];

    public function __construct(
        protected AuthService $authService,
    ) {}

    public function make(GitHost $host, string $profile = Credentials::DEFAULT_PROFILE): HostClient
    {
        $data = $this->authService->load()?->forHost($host, $profile);

        if (! $data) {
            $hint = $profile === Credentials::DEFAULT_PROFILE ? '' : " (profile '{$profile}')";
            throw new AuthenticationException("Not authenticated with {$host->label()}{$hint}. Run 'auth:{$host->value}:login' first.");
        }

        if ($this->tokenExpired($data)) {
            $data = $this->refreshToken($host, $data, $profile);
        }

        return match ($host) {
            GitHost::Github => new GithubClient($data['token']),
            GitHost::Gitlab => new GitlabClient($data['token']),
            GitHost::Bitbucket => new BitbucketClient($data['username'], $data['app_password']),
        };
    }

    /**
     * @param  array<string, string>  $data
     */
    protected function tokenExpired(array $data): bool
    {
        return ! empty($data['refresh_token']) && ! empty($data['expires_at']) && (int) $data['expires_at'] <= time();
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    protected function refreshToken(GitHost $host, array $data, string $profile): array
    {
        $authClass = self::DEVICE_AUTH_CLIENTS[$host->value]
            ?? throw new LogicException("No device flow refresh available for {$host->label()}.");

        $refreshed = (new $authClass)->refreshToken($data['refresh_token']);

        $data = [
            'token' => $refreshed['access_token'],
            'refresh_token' => $refreshed['refresh_token'] ?? $data['refresh_token'],
            'expires_at' => isset($refreshed['expires_in']) ? (string) (time() + (int) $refreshed['expires_in']) : '',
        ];

        $this->authService->saveHost($host, $data, $profile);

        return $data;
    }
}
