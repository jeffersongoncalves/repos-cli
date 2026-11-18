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
    public const CHECKS = ['clone', 'website', 'description', 'branch', 'dependabot', 'automerge', 'ci', 'immutable', 'tests', 'migrations', 'pinned'];

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

        if ($this->runs('immutable') && ! $repo->archived && $this->client->immutableReleasesEnabled($repo->owner, $repo->name) === false) {
            $add('immutable', 'Release immutability is disabled');
        }

        if ($this->runs('tests') && ($missing = $this->missingTests($repo)) !== []) {
            $add('tests', 'PHP repo without '.implode(' and ', $missing));
        }

        if ($this->runs('migrations') && ($unregistered = $this->unregisteredMigrationStubs($repo)) !== []) {
            $add('migrations', count($unregistered).' migration stub(s) missing from hasMigrations(): '.implode(', ', $unregistered));
        }

        if ($this->runs('pinned') && ($composer = $this->client->fileContent($repo->owner, $repo->name, 'composer.json')) !== null
            && ($pinned = self::pinnedConstraints($composer)) !== []) {
            $add('pinned', 'composer.json pins exact versions: '.implode(', ', $pinned));
        }

        return $findings;
    }

    /**
     * require/require-dev entries pinned to an exact version ("1.0", "2.1.3", "v3.0") instead of a range: usually a
     * lost caret (composer.bat on Windows strips ^ and ~), so the package never gets its updates.
     *
     * @return list<string> "vendor/package version"
     */
    public static function pinnedConstraints(string $composerJson): array
    {
        $data = json_decode($composerJson, true);
        if (! is_array($data)) {
            return [];
        }

        $pinned = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $package => $constraint) {
                if (is_string($constraint) && preg_match('/^v?\d+(\.\d+){0,3}(-(stable|rc|beta|alpha|patch|p)\.?\d*)?$/i', trim($constraint)) === 1) {
                    $pinned[] = "{$package} {$constraint}";
                }
            }
        }

        return $pinned;
    }

    /**
     * database/migrations/*.php.stub files the package's service provider(s) in src/ don't register.
     *
     * @return list<string>
     */
    protected function unregisteredMigrationStubs(Repo $repo): array
    {
        $stubs = array_values(array_filter(
            $this->client->listDirectory($repo->owner, $repo->name, 'database/migrations'),
            fn (string $file) => str_ends_with($file, '.php.stub'),
        ));

        if ($stubs === []) {
            return [];
        }

        $providers = array_filter(
            $this->client->listDirectory($repo->owner, $repo->name, 'src'),
            fn (string $file) => str_ends_with($file, 'ServiceProvider.php'),
        );
        $source = implode("\n", array_map(
            fn (string $file) => (string) $this->client->fileContent($repo->owner, $repo->name, "src/{$file}"),
            $providers,
        ));

        return self::unregisteredMigrations($stubs, $source);
    }

    /**
     * Stub names (without .php.stub) that never appear as a string literal in $providerSource.
     * discoversMigrations() registers every file, so nothing is missing then.
     *
     * @param  list<string>  $stubs  file names in database/migrations
     * @return list<string>
     */
    public static function unregisteredMigrations(array $stubs, string $providerSource): array
    {
        // Tokenize so comments never count: a commented-out discoversMigrations() or name is ignored.
        $source = str_starts_with(ltrim($providerSource), '<?php') ? $providerSource : "<?php\n".$providerSource;
        $tokens = array_values(array_filter(token_get_all($source), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        // ponytail: any string literal counts, so names passed via a constant/property
        // (hasMigrations(static::MIGRATIONS)) are found too. Stub names are distinctive enough.
        $registered = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING && $token[1] === 'discoversMigrations' && ($tokens[$i + 1] ?? null) === '(') {
                return [];
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $registered[] = (string) preg_replace('/\.php(\.stub)?$/', '', substr($token[1], 1, -1));
            }
        }

        $names = array_map(fn (string $stub) => substr($stub, 0, -strlen('.php.stub')), $stubs);

        return array_values(array_diff($names, $registered));
    }

    /**
     * What a PHP repo (composer.json at the root of the default branch) lacks: a tests/ folder and a
     * workflow whose file name mentions "test". Non-PHP repos are not checked.
     *
     * @return list<string>
     */
    protected function missingTests(Repo $repo): array
    {
        $root = $this->client->listDirectory($repo->owner, $repo->name);

        if (! in_array('composer.json', $root, true)) {
            return [];
        }

        $missing = in_array('tests', $root, true) ? [] : ['a tests/ folder'];
        $workflows = $this->client->listDirectory($repo->owner, $repo->name, '.github/workflows');

        if (array_filter($workflows, fn (string $file) => str_contains(strtolower($file), 'test')) === []) {
            $missing[] = 'a tests workflow';
        }

        return $missing;
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
