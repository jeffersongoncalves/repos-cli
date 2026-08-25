<?php

namespace App\Services\Hosts;

use App\Contracts\HostClient;
use App\DTOs\Issue;
use App\DTOs\Repo;
use App\Exceptions\GithubApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\Auth;

class GithubClient extends AbstractApiClient implements HostClient
{
    protected const BASE_URL = 'https://api.github.com';

    public function __construct(string $token)
    {
        parent::__construct(self::BASE_URL, Auth::bearer($token));
    }

    public function currentUsername(): string
    {
        return $this->get('user')['login'] ?? '';
    }

    public function listRepos(string $ownerOrOrg): array
    {
        $data = strcasecmp($this->currentUsername(), $ownerOrOrg) === 0
            ? $this->pagedList('user/repos', ['affiliation' => 'owner'])
            : $this->reposForOwner($ownerOrOrg);

        return array_map(fn (array $repo) => new Repo(
            owner: $repo['owner']['login'] ?? $ownerOrOrg,
            name: $repo['name'],
            sshUrl: $repo['ssh_url'],
            private: (bool) ($repo['private'] ?? false),
            defaultBranch: $repo['default_branch'] ?? null,
        ), $data);
    }

    public function listOpenIssues(string $owner, string $repo): array
    {
        $data = $this->pagedList("repos/{$owner}/{$repo}/issues", ['state' => 'open']);

        $issues = array_filter($data, fn (array $issue) => ! isset($issue['pull_request']));

        return array_map(fn (array $issue) => new Issue(
            number: $issue['number'],
            title: $issue['title'],
            url: $issue['html_url'],
            author: $issue['user']['login'] ?? null,
            updatedAt: $issue['updated_at'] ?? null,
            repo: "{$owner}/{$repo}",
        ), $issues);
    }

    /**
     * List open issues across every repo of an org/user in a single search call.
     */
    public function searchOpenIssues(string $ownerOrOrg, string $qualifier = 'org'): array
    {
        $data = $this->paginate(
            'search/issues',
            ['q' => "{$qualifier}:{$ownerOrOrg} is:issue is:open", 'per_page' => 100],
            fn (array $page) => $page['items'] ?? [],
            fn (array $page, array $query) => count($page['items'] ?? []) < 100
                ? null
                : ['query' => array_merge($query, ['page' => ($query['page'] ?? 1) + 1])],
        );

        return array_map(fn (array $issue) => new Issue(
            number: $issue['number'],
            title: $issue['title'],
            url: $issue['html_url'],
            author: $issue['user']['login'] ?? null,
            updatedAt: $issue['updated_at'] ?? null,
            repo: $this->repoFromIssueUrl($issue['repository_url'] ?? ''),
        ), $data);
    }

    protected function reposForOwner(string $owner): array
    {
        try {
            return $this->pagedList("orgs/{$owner}/repos");
        } catch (ApiException $e) {
            if ($e->statusCode !== 404) {
                throw $e;
            }

            return $this->pagedList("users/{$owner}/repos");
        }
    }

    /**
     * Page-number pagination shared by every plain-array GitHub list endpoint.
     */
    protected function pagedList(string $path, array $query = []): array
    {
        return $this->paginate(
            $path,
            array_merge($query, ['per_page' => 100]),
            fn (array $page): array => array_values($page),
            fn (array $page, array $currentQuery) => count($page) < 100
                ? null
                : ['query' => array_merge($currentQuery, ['page' => ($currentQuery['page'] ?? 1) + 1])],
        );
    }

    protected function repoFromIssueUrl(string $repositoryUrl): string
    {
        $path = parse_url($repositoryUrl, PHP_URL_PATH) ?? '';

        return ltrim(str_replace('/repos/', '', $path), '/');
    }

    protected function newApiException(int $statusCode, array $body): ApiException
    {
        return GithubApiException::fromResponse($statusCode, $body);
    }
}
