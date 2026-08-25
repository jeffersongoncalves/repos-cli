<?php

namespace App\Services;

use App\Contracts\HostClient;
use App\Enums\GitHost;
use App\Services\Hosts\BitbucketClient;
use App\Services\Hosts\GithubClient;
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

        return match ($host) {
            GitHost::Github => new GithubClient($data['token']),
            GitHost::Gitlab => new GitlabClient($data['token']),
            GitHost::Bitbucket => new BitbucketClient($data['username'], $data['app_password']),
        };
    }
}
