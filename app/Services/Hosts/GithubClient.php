<?php

namespace App\Services\Hosts;

use App\Contracts\HostClient;
use App\DTOs\Issue;
use App\DTOs\Repo;
use App\Exceptions\GithubApiException;
use GuzzleHttp\Client;
use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\Auth;

class GithubClient extends AbstractApiClient implements HostClient
{
    protected const BASE_URL = 'https://api.github.com';

    protected const MAX_ATTEMPTS = 3;

    protected const RETRY_DELAY_MS = 500;

    public function __construct(string $token, ?Client $client = null)
    {
        parent::__construct(self::BASE_URL, Auth::bearer($token), $client);
    }

    /**
     * Retries network errors (status 0) and 5xx a couple of times: a long audit makes hundreds of calls,
     * and one dropped connection shouldn't fail the whole run.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $options = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::request($method, $path, $options);
            } catch (ApiException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || ($e->statusCode !== 0 && $e->statusCode < 500)) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
            }
        }
    }

    public function currentUsername(): string
    {
        return $this->get('user')['login'] ?? '';
    }

    public function listRepos(string $ownerOrOrg): array
    {
        try {
            $login = $this->currentUsername();
        } catch (ApiException) {
            // Tokens without user scope (e.g. GitHub Actions' GITHUB_TOKEN) can't call /user:
            // fall back to the owner's public listing instead of failing.
            $login = '';
        }

        $data = strcasecmp($login, $ownerOrOrg) === 0
            ? $this->pagedList('user/repos', ['affiliation' => 'owner'])
            : $this->reposForOwner($ownerOrOrg);

        return array_map(fn (array $repo) => new Repo(
            owner: $repo['owner']['login'] ?? $ownerOrOrg,
            name: $repo['name'],
            sshUrl: $repo['ssh_url'],
            private: (bool) ($repo['private'] ?? false),
            defaultBranch: $repo['default_branch'] ?? null,
            description: $repo['description'] ?? null,
            homepage: $repo['homepage'] ?? null,
            archived: (bool) ($repo['archived'] ?? false),
            fork: (bool) ($repo['fork'] ?? false),
        ), $data);
    }

    /**
     * @return string[]
     */
    public function listBranches(string $owner, string $repo): array
    {
        return array_map(fn (array $branch) => (string) $branch['name'], $this->pagedList("repos/{$owner}/{$repo}/branches"));
    }

    /**
     * Raw content of a file on the default branch, or null when it doesn't exist.
     */
    public function fileContent(string $owner, string $repo, string $path): ?string
    {
        try {
            $file = $this->get("repos/{$owner}/{$repo}/contents/{$path}");
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }

        $content = base64_decode((string) ($file['content'] ?? ''), true);

        return $content === false ? null : $content;
    }

    /**
     * Entry names of a folder on the default branch (repo root when $path is empty); [] when it doesn't exist.
     *
     * @return list<string>
     */
    public function listDirectory(string $owner, string $repo, string $path = ''): array
    {
        try {
            $entries = $this->get(rtrim("repos/{$owner}/{$repo}/contents/{$path}", '/'));
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return [];
            }

            throw $e;
        }

        if (isset($entries['type'])) {
            return []; // $path is a file: the API returns that file's object instead of a listing
        }

        return array_map(fn (mixed $entry) => is_array($entry) ? (string) ($entry['name'] ?? '') : '', array_values($entries));
    }

    /**
     * Whether "release immutability" is on. Null when the token can't read the setting (403/404), so a
     * token without admin access doesn't turn every repo into a finding.
     */
    public function immutableReleasesEnabled(string $owner, string $repo): ?bool
    {
        try {
            return (bool) ($this->get("repos/{$owner}/{$repo}/immutable-releases")['enabled'] ?? false);
        } catch (ApiException $e) {
            if (in_array($e->statusCode, [403, 404], true)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Public, non-archived, non-fork repos of an owner that still show GitHub's generated social preview.
     * The REST repo payload doesn't carry this flag, so it comes from GraphQL (100 repos per call).
     *
     * @return list<string> repo names
     */
    public function reposWithoutSocialPreview(string $owner): array
    {
        $query = 'query($owner: String!, $cursor: String) { repositoryOwner(login: $owner) { repositories(first: 100, after: $cursor, ownerAffiliations: OWNER, privacy: PUBLIC, isFork: false) { pageInfo { hasNextPage endCursor } nodes { name isArchived usesCustomOpenGraphImage } } } }';
        $names = [];
        $cursor = null;

        do {
            $page = $this->post('graphql', ['query' => $query, 'variables' => ['owner' => $owner, 'cursor' => $cursor]])['data']['repositoryOwner']['repositories'] ?? null;
            if (! is_array($page)) {
                break;
            }

            foreach ($page['nodes'] ?? [] as $repo) {
                if (! ($repo['isArchived'] ?? false) && ! ($repo['usesCustomOpenGraphImage'] ?? true)) {
                    $names[] = (string) $repo['name'];
                }
            }

            $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $names;
    }

    /**
     * Failed push-triggered workflow runs of the branch's current HEAD commit.
     *
     * Scoped to the newest commit that has push runs. The list is requested WITHOUT a `status` filter on
     * purpose: filtered by status, GitHub doesn't reliably return the newest run first, so the check could
     * surface a months-old failure that was already fixed. One call per repo (fits GITHUB_TOKEN's rate limit).
     *
     * @return list<array{name: string, url: string}>
     */
    public function failedHeadRuns(string $owner, string $repo, string $branch): array
    {
        $runs = $this->get("repos/{$owner}/{$repo}/actions/runs", [
            'branch' => $branch,
            'event' => 'push',
            'per_page' => 30,
        ])['workflow_runs'] ?? [];

        $sha = $runs[0]['head_sha'] ?? null;

        if (! is_string($sha)) {
            return [];
        }

        $failed = array_filter($runs, fn (array $run) => ($run['head_sha'] ?? null) === $sha
            && in_array($run['conclusion'] ?? null, ['failure', 'timed_out'], true));

        return array_values(array_map(fn (array $run) => [
            'name' => (string) ($run['name'] ?? ''),
            'url' => (string) ($run['html_url'] ?? ''),
        ], $failed));
    }

    /**
     * Open Dependabot pull requests across every repo of an owner whose checks failed.
     *
     * @return array<int, array{repo: string, number: int, title: string, url: string}>
     */
    public function failingDependabotPulls(string $ownerOrOrg, string $qualifier = 'user'): array
    {
        $items = $this->paginate(
            'search/issues',
            ['q' => "{$qualifier}:{$ownerOrOrg} is:pr is:open author:app/dependabot", 'per_page' => 100],
            fn (array $page) => $page['items'] ?? [],
            fn (array $page, array $query) => count($page['items'] ?? []) < 100
                ? null
                : ['query' => array_merge($query, ['page' => ($query['page'] ?? 1) + 1])],
        );

        $failing = [];

        foreach ($items as $item) {
            $repo = $this->repoFromIssueUrl($item['repository_url'] ?? '');
            $sha = $this->get("repos/{$repo}/pulls/{$item['number']}")['head']['sha'] ?? null;

            if ($sha === null) {
                continue;
            }

            $checks = $this->get("repos/{$repo}/commits/{$sha}/check-runs", ['per_page' => 100])['check_runs'] ?? [];
            $failed = array_filter($checks, fn (array $run) => in_array($run['conclusion'] ?? null, ['failure', 'timed_out'], true));

            if ($failed !== []) {
                $failing[] = [
                    'repo' => $repo,
                    'number' => (int) $item['number'],
                    'title' => (string) $item['title'],
                    'url' => (string) $item['html_url'],
                ];
            }
        }

        return $failing;
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
