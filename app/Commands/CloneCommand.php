<?php

namespace App\Commands;

use App\Concerns\ResolvesHost;
use App\Concerns\RunsTasks;
use App\DTOs\Repo;
use App\Enums\GitHost;
use App\Services\GitOperationsService;
use App\Services\HostClientFactory;
use JeffersonGoncalves\LaravelZero\Console\HandlesApiErrors;
use JeffersonGoncalves\LaravelZero\Console\ResolvesPath;
use LaravelZero\Framework\Commands\Command;

class CloneCommand extends Command
{
    use HandlesApiErrors, ResolvesHost, ResolvesPath, RunsTasks;

    protected $signature = 'clone
        {target : A git URL, an owner/repo, or a bare owner/org to bulk-clone}
        {--host= : github|gitlab|bitbucket (auto-detected from a full URL)}
        {--path= : Destination folder (default: current directory)}';

    protected $description = 'Clone a single repo, or every repo of an owner/org (already-cloned repos are pulled instead)';

    public function handle(HostClientFactory $factory, GitOperationsService $git): int
    {
        return $this->handleApiErrors(function () use ($factory, $git) {
            $target = $this->argument('target');
            $destination = $this->resolvePath($this->option('path'));

            if (str_contains($target, '/') || str_contains($target, ':')) {
                return $this->cloneSingle($git, $target, $destination);
            }

            return $this->cloneAllForOwner($factory, $git, $target, $destination);
        });
    }

    protected function cloneSingle(GitOperationsService $git, string $target, string $destination): int
    {
        $isUrl = str_contains($target, '://') || str_starts_with($target, 'git@');

        $url = $isUrl
            ? $target
            : $this->buildSshUrl($this->resolveHost($this->option('host')), $target);

        $this->runTask("Cloning {$url}", fn () => $git->clone($url, $destination));

        return self::SUCCESS;
    }

    protected function cloneAllForOwner(HostClientFactory $factory, GitOperationsService $git, string $ownerOrOrg, string $destination): int
    {
        $host = $this->resolveHost($this->option('host'));
        $client = $factory->make($host);
        $repos = $client->listRepos($ownerOrOrg);

        if ($repos === []) {
            $this->components->warn("No repositories found for {$ownerOrOrg} on {$host->label()}.");

            return self::SUCCESS;
        }

        foreach ($repos as $repo) {
            $this->syncOne($git, $repo, $destination);
        }

        return self::SUCCESS;
    }

    protected function buildSshUrl(GitHost $host, string $target): string
    {
        [$owner, $repo] = array_pad(explode('/', $target, 2), 2, '');

        return $host->sshUrl($owner, $repo);
    }

    protected function syncOne(GitOperationsService $git, Repo $repo, string $destination): void
    {
        $path = $destination.DIRECTORY_SEPARATOR.$repo->name;

        $this->runTask($repo->fullName(), fn () => $git->isGitRepo($path)
            ? $git->pull($path)
            : $git->clone($repo->sshUrl, $path));
    }
}
