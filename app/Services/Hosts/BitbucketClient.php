<?php

namespace App\Services\Hosts;

use App\Contracts\HostClient;
use App\DTOs\Issue;
use App\DTOs\Repo;
use App\Exceptions\BitbucketApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\Auth;

class BitbucketClient extends AbstractApiClient implements HostClient
{
    protected const BASE_URL = 'https://api.bitbucket.org/2.0';

    public function __construct(string $username, string $appPassword)
    {
        parent::__construct(self::BASE_URL, Auth::basic($username, $appPassword));
    }

    public function currentUsername(): string
    {
        return $this->get('user')['username'] ?? '';
    }

    public function listRepos(string $ownerOrOrg): array
    {
        $data = $this->paginate(
            "repositories/{$ownerOrOrg}",
            ['pagelen' => 100],
            fn (array $page) => $page['values'] ?? [],
            fn (array $page) => isset($page['next']) && is_string($page['next'])
                ? ['path' => $page['next'], 'query' => []]
                : null,
        );

        return array_map(fn (array $repo) => new Repo(
            owner: $ownerOrOrg,
            name: $repo['slug'],
            sshUrl: $this->sshUrlFrom($repo),
            private: (bool) ($repo['is_private'] ?? true),
            defaultBranch: $repo['mainbranch']['name'] ?? null,
        ), $data);
    }

    public function listOpenIssues(string $owner, string $repo): array
    {
        try {
            $data = $this->paginate(
                "repositories/{$owner}/{$repo}/issues",
                ['q' => 'state = "new" OR state = "open"', 'pagelen' => 100],
                fn (array $page) => $page['values'] ?? [],
                fn (array $page) => isset($page['next']) && is_string($page['next'])
                    ? ['path' => $page['next'], 'query' => []]
                    : null,
            );
        } catch (ApiException) {
            // Issue tracker not enabled for this repo.
            return [];
        }

        return array_map(fn (array $issue) => new Issue(
            number: $issue['id'],
            title: $issue['title'],
            url: $issue['links']['html']['href'] ?? '',
            author: $issue['reporter']['display_name'] ?? null,
            updatedAt: $issue['updated_on'] ?? null,
            repo: "{$owner}/{$repo}",
        ), $data);
    }

    protected function sshUrlFrom(array $repo): string
    {
        foreach ($repo['links']['clone'] ?? [] as $clone) {
            if (($clone['name'] ?? null) === 'ssh') {
                return $clone['href'];
            }
        }

        return '';
    }

    protected function newApiException(int $statusCode, array $body): ApiException
    {
        return BitbucketApiException::fromResponse($statusCode, $body);
    }
}
