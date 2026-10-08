<?php

namespace App\Commands;

use App\Concerns\ResolvesHost;
use App\DTOs\Finding;
use App\DTOs\Repo;
use App\Enums\GitHost;
use App\Services\CatalogAuditor;
use App\Services\GitOperationsService;
use App\Services\HostClientFactory;
use App\Services\Hosts\GithubClient;
use App\Services\RepoAuditor;
use InvalidArgumentException;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\Console\FormatsOutput;
use JeffersonGoncalves\LaravelZero\Console\HandlesApiErrors;
use JeffersonGoncalves\LaravelZero\Console\ResolvesPath;
use LaravelZero\Framework\Commands\Command;

class AuditCommand extends Command
{
    use FormatsOutput, HandlesApiErrors, ResolvesHost, ResolvesPath;

    protected $signature = 'audit
        {owner : GitHub user or org to audit}
        {--host=github : Only github is supported}
        {--profile=default : Named credential profile to use for the API calls}
        {--path= : Folder that should hold a clone of every repo (default: current directory)}
        {--only= : Comma-separated checks to run (clone,website,description,branch,dependabot,automerge,ci,immutable,dependabot-prs,social,catalog)}
        {--skip= : Comma-separated checks to skip}
        {--automerge-template= : Approved dependabot-auto-merge.yml to compare against (automerge check is skipped without it)}
        {--catalog= : Product catalog (plugins.json) to cross-check against GitHub and Packagist (catalog check is skipped without it)}
        {--catalog-ignore= : Comma-separated vendor/package names the catalog check leaves out (e.g. an app published on Packagist)}
        {--filter= : Only audit repos whose name contains this text}
        {--exclude= : Comma-separated repo names to skip (exact name, case-insensitive)}
        {--include-archived : Also audit archived repos}
        {--include-forks : Also audit forks}
        {--qualifier=user : user or org (search used by the dependabot-prs check)}
        {--json : Print the findings as JSON}';

    protected $description = 'Audit every repo of an owner: local clone, website, description, default branch, Dependabot setup, CI, release immutability, failing Dependabot PRs, social preview and the product catalog';

    public function handle(HostClientFactory $factory, GitOperationsService $git, CatalogAuditor $catalogAuditor): int
    {
        return $this->handleApiErrors(function () use ($factory, $git, $catalogAuditor) {
            if ($this->resolveHost($this->option('host')) !== GitHost::Github) {
                throw new InvalidArgumentException('audit only supports --host=github.');
            }

            $client = $factory->make(GitHost::Github, (string) $this->option('profile'));
            if (! $client instanceof GithubClient) {
                throw new InvalidArgumentException('audit needs a GitHub client.');
            }

            $checks = $this->selectedChecks();
            $owner = (string) $this->argument('owner');
            $allRepos = $client->listRepos($owner);
            $repos = $this->repos($allRepos);

            $template = $this->option('automerge-template');
            if (is_string($template) && $template !== '' && ! is_file($template)) {
                throw new InvalidArgumentException("Template not found: {$template}");
            }

            $catalog = $this->option('catalog');
            if (is_string($catalog) && $catalog !== '' && ! is_file($catalog)) {
                throw new InvalidArgumentException("Catalog not found: {$catalog}");
            }

            $auditor = new RepoAuditor(
                $client,
                $git,
                $checks,
                in_array('clone', $checks, true) ? $this->resolvePath($this->option('path')) : null,
                is_string($template) && $template !== '' ? (string) file_get_contents($template) : null,
            );

            $findings = [];
            $auditOne = function (Repo $repo) use ($auditor, &$findings): void {
                try {
                    array_push($findings, ...$auditor->audit($repo));
                } catch (ApiException $e) {
                    // Keep going: one unreachable repo shouldn't hide the rest of the report.
                    $findings[] = new Finding($repo->fullName(), 'error', "Could not audit: {$e->getMessage()}");
                }
            };

            if ($this->option('json')) {
                // stdout must stay pure JSON so the output can be piped.
                array_walk($repos, $auditOne);
            } else {
                $this->withProgressBar($repos, $auditOne);
                $this->newLine(2);
            }

            if (in_array('dependabot-prs', $checks, true)) {
                foreach ($client->failingDependabotPulls($owner, (string) $this->option('qualifier')) as $pull) {
                    if ($this->matchesFilter($pull['repo'])) {
                        $findings[] = new Finding($pull['repo'], 'dependabot-prs', "PR #{$pull['number']} failing: {$pull['url']}");
                    }
                }
            }

            if (in_array('social', $checks, true)) {
                foreach ($client->reposWithoutSocialPreview($owner) as $name) {
                    if ($this->matchesFilter($name)) {
                        $findings[] = new Finding("{$owner}/{$name}", 'social', 'Public repo without a custom social preview image');
                    }
                }
            }

            if (in_array('catalog', $checks, true) && is_string($catalog) && $catalog !== '') {
                $entries = json_decode((string) file_get_contents($catalog), true);
                if (! is_array($entries)) {
                    throw new InvalidArgumentException("Catalog is not valid JSON: {$catalog}");
                }

                $ignored = array_map('strtolower', array_filter(array_map('trim', explode(',', (string) $this->option('catalog-ignore')))));
                foreach ($catalogAuditor->audit($entries, $allRepos, $owner) as $finding) {
                    if ($this->matchesFilter($finding->repo) && ! in_array(strtolower($finding->repo), $ignored, true)) {
                        $findings[] = $finding;
                    }
                }
            }

            $this->report($findings, count($repos));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        });
    }

    /**
     * @return list<string>
     */
    protected function selectedChecks(): array
    {
        $all = [...RepoAuditor::CHECKS, 'dependabot-prs', 'social', 'catalog'];
        $split = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        $only = $split($this->option('only'));
        $skip = $split($this->option('skip'));

        foreach ([...$only, ...$skip] as $check) {
            if (! in_array($check, $all, true)) {
                throw new InvalidArgumentException("Unknown check '{$check}'. Available: ".implode(', ', $all));
            }
        }

        return array_values(array_diff($only === [] ? $all : $only, $skip));
    }

    /**
     * @param  list<Repo>  $all
     * @return list<Repo>
     */
    protected function repos(array $all): array
    {
        return array_values(array_filter(
            $all,
            fn (Repo $repo) => ($this->option('include-archived') || ! $repo->archived)
                && ($this->option('include-forks') || ! $repo->fork)
                && $this->matchesFilter($repo->name),
        ));
    }

    /**
     * Name filter + exclusion list. Accepts a bare name or an owner/name (Dependabot PR findings).
     */
    protected function matchesFilter(string $name): bool
    {
        $name = strtolower(str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name);
        $filter = strtolower((string) $this->option('filter'));
        $excluded = array_map('strtolower', array_filter(array_map('trim', explode(',', (string) $this->option('exclude')))));

        return ($filter === '' || str_contains($name, $filter)) && ! in_array($name, $excluded, true);
    }

    /**
     * @param  list<Finding>  $findings
     */
    protected function report(array $findings, int $repoCount): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        usort($findings, fn (Finding $a, Finding $b) => [$a->check, $a->repo] <=> [$b->check, $b->repo]);
        $this->renderTable(['Check', 'Repo', 'Problem'], array_map(fn (Finding $f) => [$f->check, $f->repo, $f->message], $findings));

        $byCheck = array_count_values(array_map(fn (Finding $f) => $f->check, $findings));
        $summary = $byCheck === [] ? 'no problems' : implode(', ', array_map(fn ($check, $n) => "{$check}: {$n}", array_keys($byCheck), $byCheck));
        $this->components->info("Audited {$repoCount} repos — {$summary}.");
    }
}
