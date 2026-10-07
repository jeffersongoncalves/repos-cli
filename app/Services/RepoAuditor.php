<?php

namespace App\Services;

use App\DTOs\Finding;
use App\DTOs\Repo;
use App\Services\Hosts\GithubClient;

/**
 * Per-repository hygiene checks behind `repos audit`.
 */
class RepoAuditor
{
    public const CHECKS = ['clone', 'website', 'description', 'branch', 'dependabot', 'automerge', 'ci'];

    /**
     * @param  list<string>  $checks  subset of self::CHECKS to run
     * @param  string|null  $localPath  folder that should hold a clone of every repo (null skips `clone`)
     * @param  string|null  $automergeTemplate  approved dependabot-auto-merge.yml (null skips `automerge`)
     */
    public function __construct(
        protected GithubClient $client,
        protected GitOperationsService $git,
        protected array $checks = self::CHECKS,
        protected ?string $localPath = null,
        protected ?string $automergeTemplate = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function audit(Repo $repo): array
    {
        $findings = [];
        $add = function (string $check, string $message) use (&$findings, $repo): void {
            $findings[] = new Finding($repo->fullName(), $check, $message);
        };

        if ($this->runs('clone') && $this->localPath !== null && ! $this->git->isGitRepo($this->localPath.DIRECTORY_SEPARATOR.$repo->name)) {
            $add('clone', "No local clone in {$this->localPath}");
        }

        if ($this->runs('website') && $repo->homepage !== null && str_contains(strtolower($repo->homepage), 'packagist.org')) {
            $add('website', "Website points to Packagist ({$repo->homepage})");
        }

        if ($this->runs('description') && trim((string) $repo->description) === '') {
            $add('description', 'Empty description');
        }

        if ($this->runs('branch')) {
            $expected = self::expectedDefaultBranch($this->client->listBranches($repo->owner, $repo->name));
            if ($repo->defaultBranch !== null && $repo->defaultBranch !== $expected) {
                $add('branch', "Default branch is {$repo->defaultBranch}, expected {$expected}");
            }
        }

        if ($this->runs('dependabot') && $this->client->fileContent($repo->owner, $repo->name, '.github/dependabot.yml') === null) {
            $add('dependabot', 'Missing .github/dependabot.yml');
        }

        if ($this->runs('automerge') && $this->automergeTemplate !== null) {
            $automerge = $this->client->fileContent($repo->owner, $repo->name, '.github/workflows/dependabot-auto-merge.yml');
            if ($automerge !== null && ! self::sameContent($automerge, $this->automergeTemplate)) {
                $add('automerge', 'dependabot-auto-merge.yml differs from the approved template');
            }
        }

        if ($this->runs('ci') && $repo->defaultBranch !== null) {
            foreach ($this->client->failedHeadRuns($repo->owner, $repo->name, $repo->defaultBranch) as $run) {
                $add('ci', "{$run['name']} failed on the {$repo->defaultBranch} HEAD commit: {$run['url']}");
            }
        }

        return $findings;
    }

    /**
     * Multi-branch packages default to their highest `N.x` branch; everything else to `main`.
     *
     * @param  string[]  $branches
     */
    public static function expectedDefaultBranch(array $branches): string
    {
        $versions = array_values(array_filter($branches, fn (string $b) => preg_match('/^\d+\.x$/', $b) === 1));

        if ($versions === []) {
            return 'main';
        }

        usort($versions, fn (string $a, string $b) => (int) $b <=> (int) $a);

        return $versions[0];
    }

    /**
     * Compares two files ignoring line endings and surrounding whitespace.
     */
    public static function sameContent(string $a, string $b): bool
    {
        $normalize = fn (string $s) => trim(str_replace("\r\n", "\n", $s));

        return $normalize($a) === $normalize($b);
    }

    protected function runs(string $check): bool
    {
        return in_array($check, $this->checks, true);
    }
}
