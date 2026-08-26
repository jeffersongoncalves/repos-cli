<?php

namespace App\Commands;

use App\Concerns\ResolvesHost;
use App\Services\HostClientFactory;
use App\Services\Hosts\GithubClient;
use JeffersonGoncalves\LaravelZero\Console\FormatsOutput;
use JeffersonGoncalves\LaravelZero\Console\HandlesApiErrors;
use LaravelZero\Framework\Commands\Command;

class IssuesCommand extends Command
{
    use FormatsOutput, HandlesApiErrors, ResolvesHost;

    protected $signature = 'issues
        {target : owner/repo for a single repo, or a bare owner/org for every repo}
        {--host= : github|gitlab|bitbucket}
        {--profile=default : Named credential profile to use for the API calls}
        {--qualifier=org : (GitHub bulk search only) org or user}';

    protected $description = 'List open issues for a repo, or across every repo of an owner/org';

    public function handle(HostClientFactory $factory): int
    {
        return $this->handleApiErrors(function () use ($factory) {
            $target = $this->argument('target');
            $host = $this->resolveHost($this->option('host'), $target);
            $client = $factory->make($host, (string) $this->option('profile'));

            if (str_contains($target, '/')) {
                [$owner, $repo] = explode('/', $target, 2);
                $issues = $client->listOpenIssues($owner, $repo);
            } elseif ($client instanceof GithubClient) {
                $issues = $client->searchOpenIssues($target, (string) $this->option('qualifier'));
            } else {
                $issues = collect($client->listRepos($target))
                    ->flatMap(fn ($repo) => $client->listOpenIssues($repo->owner, $repo->name))
                    ->all();
            }

            $rows = array_map(fn ($issue) => [
                $issue->repo,
                '#'.$issue->number,
                mb_substr($issue->title, 0, 60),
                $issue->author ?? '-',
                $this->formatDate($issue->updatedAt),
            ], $issues);

            $this->renderTable(['Repo', '#', 'Title', 'Author', 'Updated'], $rows);

            return self::SUCCESS;
        });
    }
}
