<?php

namespace App\Services;

use App\Contracts\HostClient;
use App\Enums\GitHost;
use App\Services\Hosts\BitbucketClient;
use App\Services\Hosts\GithubClient;
use App\Services\Hosts\GithubDeviceAuth;
use App\Services\Hosts\GitlabClient;
use JeffersonGoncalves\LaravelZero\Credentials\AuthenticationException;

class HostClientFactory
{
    public function __construct(
        protected AuthService $authService,
    ) {}

    public function make(GitHost $host): HostClient
    {
        $data = $this->authService->load()?->forHost($host);

        if (! $data) {
            throw new AuthenticationException("Not authenticated with {$host->label()}. Run 'auth:{$host->value}:login' first.");
        }

        if ($host === GitHost::Github && $this->githubTokenExpired($data)) {
            $data = $this->refreshGithubToken($data);
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
    protected function githubTokenExpired(array $data): bool
    {
        return ! empty($data['refresh_token']) && ! empty($data['expires_at']) && (int) $data['expires_at'] <= time();
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    protected function refreshGithubToken(array $data): array
    {
        $refreshed = (new GithubDeviceAuth)->refreshToken($data['refresh_token']);

        $data = [
            'token' => $refreshed['access_token'],
            'refresh_token' => $refreshed['refresh_token'] ?? $data['refresh_token'],
            'expires_at' => isset($refreshed['expires_in']) ? (string) (time() + (int) $refreshed['expires_in']) : '',
        ];

        $this->authService->saveHost(GitHost::Github, $data);

        return $data;
    }
}
