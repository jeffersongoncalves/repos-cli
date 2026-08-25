<?php

namespace App\Services\Hosts;

use App\Contracts\HostClient;
use App\DTOs\Issue;
use App\DTOs\Repo;
use App\Exceptions\GitlabApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;

class GitlabClient extends AbstractApiClient implements HostClient
{
    protected const BASE_URL = 'https://gitlab.com/api/v4';

    public function __construct(
        protected string $token,
    ) {
        parent::__construct(self::BASE_URL);
    }

    public function currentUsername(): string
    {
        return $this->get('user')['username'] ?? '';
    }

    public function listRepos(string $ownerOrOrg): array
    {
        $data = $this->projectsForNamespace($ownerOrOrg);

        return array_map(fn (array $project) => new Repo(
            owner: $ownerOrOrg,
            name: $project['path'],
            sshUrl: $project['ssh_url_to_repo'],
            private: ($project['visibility'] ?? 'private') !== 'public',
            defaultBranch: $project['default_branch'] ?? null,
        ), $data);
    }

    public function listOpenIssues(string $owner, string $repo): array
    {
        $projectId = rawurlencode("{$owner}/{$repo}");

        $data = $this->pagedList("projects/{$projectId}/issues", ['state' => 'opened']);

        return array_map(fn (array $issue) => new Issue(
            number: $issue['iid'],
            title: $issue['title'],
            url: $issue['web_url'],
            author: $issue['author']['username'] ?? null,
            updatedAt: $issue['updated_at'] ?? null,
            repo: "{$owner}/{$repo}",
        ), $data);
    }

    protected function projectsForNamespace(string $namespace): array
    {
        try {
            return $this->pagedList('groups/'.rawurlencode($namespace).'/projects', ['include_subgroups' => 'true']);
        } catch (ApiException $e) {
            if ($e->statusCode !== 404) {
                throw $e;
            }

            return $this->pagedList('users/'.rawurlencode($namespace).'/projects');
        }
    }

    /**
     * Page-number pagination shared by every GitLab list endpoint.
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

    protected function authHeaders(): array
    {
        return ['PRIVATE-TOKEN' => $this->token];
    }

    protected function newApiException(int $statusCode, array $body): ApiException
    {
        return GitlabApiException::fromResponse($statusCode, $body);
    }
}
