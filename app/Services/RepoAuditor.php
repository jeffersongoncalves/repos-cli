<?php

namespace App\Services;

use App\DTOs\Finding;
use App\DTOs\Repo;
use App\Services\Hosts\GithubClient;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Per-repository hygiene checks behind `repos audit`.
 */
class RepoAuditor
{
    public const CHECKS = ['clone', 'website', 'description', 'branch', 'dependabot', 'automerge', 'ci', 'immutable', 'tests', 'migrations', 'pinned', 'compat', 'dependabot-ignore', 'kit-drift', 'stale-own', 'own-major', 'changelog'];

    /**
     * Files every starter kit keeps identical to its FilaKit base (filakit / filakitv4 / filakitv5): infra and
     * framework config, not the kit's own features. A fix made in the base must reach every kit.
     */
    public const KIT_SHARED_FILES = [
        '.editorconfig', '.gitattributes', '.github/FUNDING.yml',
        '.github/workflows/auto-assign.yml', '.github/workflows/auto-release-filament.yml',
        '.github/workflows/dependabot-auto-merge.yml', '.github/workflows/fix-php-code-style-issues.yml',
        '.github/workflows/phpstan.yml', '.github/workflows/tests.yml', '.github/workflows/update-changelog.yml',
        'artisan', 'install.php', 'phpstan.neon.dist', 'phpunit.xml',
        'config/blade-icons.php', 'config/cache.php', 'config/database.php', 'config/filesystems.php', 'config/logging.php',
        'config/mail.php', 'config/queue.php', 'config/security-headers.php', 'config/services.php', 'config/session.php',
    ];

    /** @var array<string, array<string, string>> base kit => its tree, fetched once per run */
    protected array $baseTrees = [];

    /** @var array<string, list<string>> package => its released versions, fetched once per run */
    protected array $versions = [];

    /** @var Closure(string): list<string> */
    protected Closure $releasedVersions;

    /**
     * @param  list<string>  $checks  subset of self::CHECKS to run
     * @param  string|null  $localPath  folder that should hold a clone of every repo (null skips `clone`)
     * @param  string|null  $automergeTemplate  approved dependabot-auto-merge.yml (null skips `automerge`)
     * @param  (Closure(string): list<string>)|null  $releasedVersions  versions of a package on Packagist
     * @param  string|null  $changelogUpdaterSha  approved changelog-updater-action commit (null skips `changelog`)
     */
    public function __construct(
        protected GithubClient $client,
        protected GitOperationsService $git,
        protected array $checks = self::CHECKS,
        protected ?string $localPath = null,
        protected ?string $automergeTemplate = null,
        ?Closure $releasedVersions = null,
        protected ?string $changelogUpdaterSha = null,
    ) {
        $this->releasedVersions = $releasedVersions ?? function (string $package): array {
            try {
                $response = (new Client(['timeout' => 30]))->get("https://repo.packagist.org/p2/{$package}.json");
            } catch (GuzzleException) {
                return [];
            }
            $data = json_decode((string) $response->getBody(), true);

            return array_values(array_map(fn ($v) => (string) ($v['version'] ?? ''), (array) ($data['packages'][$package] ?? [])));
        };
    }

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

        $branches = null;
        $versionBranches = function () use (&$branches, $repo): array {
            $branches ??= $this->client->listBranches($repo->owner, $repo->name);

            return array_values(array_filter($branches, fn (string $b) => preg_match('/^\d+\.x$/', $b) === 1));
        };
        $composers = [];
        $composerAt = function (?string $ref) use (&$composers, $repo): ?string {
            $key = $ref ?? '';
            if (! array_key_exists($key, $composers)) {
                $composers[$key] = $this->client->fileContent($repo->owner, $repo->name, 'composer.json', $ref);
            }

            return $composers[$key];
        };
        $dependabot = false; // not fetched yet
        $dependabotYml = function () use (&$dependabot, $repo): ?string {
            if ($dependabot === false) {
                $dependabot = $this->client->fileContent($repo->owner, $repo->name, '.github/dependabot.yml');
            }

            return $dependabot;
        };

        if ($this->runs('branch')) {
            $branches = $this->client->listBranches($repo->owner, $repo->name);
            $expected = self::expectedDefaultBranch($branches);
            if ($repo->defaultBranch !== null && $repo->defaultBranch !== $expected) {
                $add('branch', "Default branch is {$repo->defaultBranch}, expected {$expected}");
            }
        }

        if ($this->runs('dependabot') && $dependabotYml() === null) {
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

        if ($this->runs('pinned') && ($composer = $composerAt(null)) !== null
            && ($pinned = self::pinnedConstraints($composer)) !== []) {
            $add('pinned', 'composer.json pins exact versions: '.implode(', ', $pinned));
        }

        if ($this->runs('compat') || $this->runs('dependabot-ignore')) {
            // N.x branch => [Filament package, major] read from that branch's composer.json.
            $filament = [];
            foreach ($versionBranches() as $branch) {
                $requirement = self::filamentRequirement((string) $composerAt($branch));
                if ($requirement !== null) {
                    $filament[$branch] = $requirement;
                }
            }

            if ($filament !== [] && $this->runs('compat')) {
                foreach (self::compatProblems((string) $this->client->fileContent($repo->owner, $repo->name, 'README.md'), $filament) as $problem) {
                    $add('compat', $problem);
                }
            }

            if ($filament !== [] && $this->runs('dependabot-ignore') && ($yml = $dependabotYml()) !== null) {
                foreach (self::dependabotIgnoreProblems($yml, $filament) as $problem) {
                    $add('dependabot-ignore', $problem);
                }
            }
        }

        if (($this->runs('kit-drift') || $this->runs('stale-own')) && ($base = self::kitBase($repo->name)) !== null) {
            $tree = $this->client->treeShas($repo->owner, $repo->name);
            // Every kit is a Laravel app with FilaKit's install.php (each renames config/filakit.php to its own).
            if (isset($tree['artisan'], $tree['install.php'])) {
                if ($this->runs('kit-drift')) {
                    $this->baseTrees[$base] ??= $this->client->treeShas($repo->owner, $base);
                    if (($drift = self::kitDrift($tree, $this->baseTrees[$base])) !== []) {
                        $add('kit-drift', "differs from {$base}: ".implode(', ', $drift));
                    }
                }

                if ($this->runs('stale-own') && isset($tree['composer.lock'])) {
                    $behind = self::staleOwnPackages(
                        (string) $this->client->fileContent($repo->owner, $repo->name, 'composer.lock'),
                        $repo->owner,
                        fn (string $package) => $this->versions[$package] ??= ($this->releasedVersions)($package),
                    );
                    if ($behind !== []) {
                        $add('stale-own', 'composer.lock is behind your own releases: '.implode(', ', $behind));
                    }
                }
            }
        }

        if ($this->runs('own-major')) {
            foreach ($versionBranches() ?: [null] as $branch) {
                $composer = $composerAt($branch);
                if ($composer === null) {
                    continue;
                }
                $behind = self::ownMajorProblems($composer, $repo->owner, fn (string $package) => $this->versions[$package] ??= ($this->releasedVersions)($package));
                if ($behind !== []) {
                    $add('own-major', ($branch === null ? '' : "{$branch}: ").'requires an older major of your own packages: '.implode(', ', $behind));
                }
            }
        }

        if ($this->runs('changelog') && $this->changelogUpdaterSha !== null) {
            // A release runs the workflow of its tag's commit, so every branch that gets tagged counts.
            foreach (array_unique([$repo->defaultBranch, ...$versionBranches()], SORT_REGULAR) as $branch) {
                $workflow = (string) $this->client->fileContent($repo->owner, $repo->name, '.github/workflows/update-changelog.yml', $branch);
                foreach (self::changelogPinProblems($workflow, $this->changelogUpdaterSha) as $problem) {
                    $add('changelog', ($branch ?? 'default branch').": {$problem}");
                }
            }
        }

        return $findings;
    }

    /**
     * The changelog-updater-action references of update-changelog.yml that aren't the approved commit: another SHA,
     * or a tag/branch (which floats).
     *
     * @return list<string>
     */
    public static function changelogPinProblems(string $workflow, string $approvedSha): array
    {
        try {
            $data = Yaml::parse($workflow);
        } catch (ParseException $e) {
            return ["update-changelog.yml is not valid YAML: {$e->getMessage()}"];
        }

        $refs = [];
        foreach ((array) data_get($data, 'jobs.*.steps.*.uses', []) as $uses) {
            if (is_string($uses) && preg_match('#^stefanzweifel/changelog-updater-action@(.+)$#i', trim($uses), $m) === 1) {
                $refs[] = $m[1];
            }
        }

        $expected = substr($approvedSha, 0, 7);
        $problems = [];

        foreach (array_unique($refs) as $ref) {
            if (preg_match('/^[0-9a-f]{40}$/i', $ref) !== 1) {
                $problems[] = "update-changelog.yml uses the floating changelog-updater-action@{$ref} (expected {$expected})";
            } elseif (strcasecmp($ref, $approvedSha) !== 0) {
                $problems[] = 'update-changelog.yml pins changelog-updater-action '.substr($ref, 0, 7)." (expected {$expected})";
            }
        }

        return $problems;
    }

    /**
     * The owner's Filament-agnostic requirements (vendor/* except vendor/filament-*, whose major follows the
     * branch's Filament version) whose constraint does not allow the newest released major.
     *
     * @param  Closure(string): list<string>  $releasedVersions
     * @return list<string> "vendor/package ^1.0 (latest 2.x)"
     */
    public static function ownMajorProblems(string $composerJson, string $owner, Closure $releasedVersions): array
    {
        $require = (array) (json_decode($composerJson, true)['require'] ?? []);
        $prefix = strtolower($owner).'/';
        $problems = [];

        foreach ($require as $package => $constraint) {
            $name = strtolower((string) $package);
            if (! is_string($constraint) || ! str_starts_with($name, $prefix) || str_starts_with($name, $prefix.'filament-')) {
                continue;
            }

            $majors = [];
            foreach ($releasedVersions((string) $package) as $version) {
                if (preg_match('/^v?(\d+)\.\d+\.\d+$/', $version, $m) === 1) {
                    $majors[] = (int) $m[1];
                }
            }
            if ($majors === []) {
                continue;
            }

            $latest = max($majors);
            $allowed = array_filter(preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [], fn (string $part) => preg_match('/^[\^~]?v?'.$latest.'(\.\d+|\.\*)*$/', $part) === 1);
            if ($allowed === []) {
                $problems[] = "{$package} {$constraint} (latest {$latest}.x)";
            }
        }

        return $problems;
    }

    /**
     * The FilaKit base of a starter kit, by name: "*kitv4" → filakitv4, "*kitv5"/"*themev5" → filakitv5, a v3 or
     * unversioned kit → filakit. Null for the bases themselves and for anything not named like a kit.
     */
    public static function kitBase(string $name): ?string
    {
        if (preg_match('/^filakit(v\d+)?$/', $name) === 1 || preg_match('/(kit|theme)(v(\d+))?$/', $name, $m) !== 1) {
            return null;
        }

        return match ($m[3] ?? '3') {
            '4' => 'filakitv4',
            '5' => 'filakitv5',
            default => 'filakit',
        };
    }

    /**
     * The owner's packages locked at an older version than their newest stable release of the same major (what a
     * plain `composer update` would bring): the kit ships without the latest fixes of its own packages.
     *
     * @param  Closure(string): list<string>  $releasedVersions
     * @return list<string> "vendor/package 1.2.0 → 1.3.1"
     */
    public static function staleOwnPackages(string $composerLock, string $owner, Closure $releasedVersions): array
    {
        $lock = json_decode($composerLock, true);
        $stable = '/^v?(\d+)\.\d+\.\d+$/';
        $behind = [];

        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            $name = (string) ($package['name'] ?? '');
            $locked = (string) ($package['version'] ?? '');
            if (! str_starts_with(strtolower($name), strtolower($owner).'/') || preg_match($stable, $locked, $m) !== 1) {
                continue;
            }

            $newest = $locked;
            foreach ($releasedVersions($name) as $version) {
                if (preg_match($stable, $version, $v) === 1 && $v[1] === $m[1] && version_compare(ltrim($version, 'v'), ltrim($newest, 'v'), '>')) {
                    $newest = $version;
                }
            }

            if ($newest !== $locked) {
                $behind[] = "{$name} {$locked} → {$newest}";
            }
        }

        return $behind;
    }

    /**
     * Shared files whose blob differs from the base's ("(missing)" when the kit lacks one the base has).
     *
     * @param  array<string, string>  $kit  path => blob SHA
     * @param  array<string, string>  $base  path => blob SHA
     * @return list<string>
     */
    public static function kitDrift(array $kit, array $base): array
    {
        $drift = [];
        foreach (self::KIT_SHARED_FILES as $file) {
            if (isset($base[$file]) && ($kit[$file] ?? null) !== $base[$file]) {
                $drift[] = isset($kit[$file]) ? $file : "{$file} (missing)";
            }
        }

        return $drift;
    }

    /**
     * The Filament package a branch's composer.json requires (filament/filament first, else any filament/*) and the
     * major of its constraint ("^4.0" → 4).
     *
     * @return array{0: string, 1: int}|null
     */
    public static function filamentRequirement(string $composerJson): ?array
    {
        $require = (array) (json_decode($composerJson, true)['require'] ?? []);
        $names = array_values(array_filter(array_keys($require), fn ($name) => str_starts_with((string) $name, 'filament/')));
        usort($names, fn ($a, $b) => ($b === 'filament/filament') <=> ($a === 'filament/filament'));

        foreach ($names as $name) {
            if (is_string($require[$name]) && preg_match('/\d+/', $require[$name], $m) === 1) {
                return [(string) $name, (int) $m[0]];
            }
        }

        return null;
    }

    /**
     * The README's compatibility table (rows starting with an "N.x" cell) must list every version branch with the
     * Filament major that branch requires, and no branch that doesn't exist.
     *
     * @param  array<string, array{0: string, 1: int}>  $filament  branch => [package, major]
     * @return list<string>
     */
    public static function compatProblems(string $readme, array $filament): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $readme) ?: [] as $line) {
            if (! str_starts_with(trim($line), '|')) {
                if ($rows !== []) {
                    break; // only the first table with version rows: later tables (screenshots, options) can hold "4.x" too
                }

                continue;
            }
            // "**1.x**", "`1.x`" and "[1.x](…/tree/1.x)" are all the 1.x cell, in any column.
            $cells = array_values(array_filter(array_map(
                fn (string $c) => trim((string) preg_replace('/^\[(.*)\]\(.*\)$/', '$1', trim(str_replace(['*', '`'], '', $c)))),
                explode('|', trim($line)),
            ), fn (string $c) => $c !== ''));
            foreach ($cells as $i => $cell) {
                if (preg_match('/^\d+\.x$/', $cell) === 1) {
                    unset($cells[$i]);
                    $rows[$cell] ??= implode(' | ', $cells);

                    break;
                }
            }
        }

        if ($rows === []) {
            return ['README has no compatibility table (version branch → Filament)'];
        }

        $problems = [];
        foreach ($filament as $branch => [, $major]) {
            if (! isset($rows[$branch])) {
                $problems[] = "README compatibility table has no row for {$branch} (Filament {$major})";
            } elseif (preg_match('/(?<![\d.])'.$major.'(\.x|\.\d+)?(?![\d.])/', $rows[$branch]) !== 1) {
                $problems[] = "README says {$branch} is for \"{$rows[$branch]}\", but its composer.json requires Filament {$major}";
            }
        }
        foreach (array_diff(array_keys($rows), array_keys($filament)) as $stale) {
            $problems[] = "README compatibility table lists {$stale}, which is not a version branch";
        }

        return $problems;
    }

    /**
     * Each version branch's composer entry in dependabot.yml must ignore the next major of its Filament package, or
     * Dependabot opens cross-major PRs (the next major lives on the next branch).
     *
     * @param  array<string, array{0: string, 1: int}>  $filament  branch => [package, major]
     * @return list<string>
     */
    public static function dependabotIgnoreProblems(string $yml, array $filament): array
    {
        $entries = [];
        foreach (preg_split('/^\s*-\s*package-ecosystem:/m', $yml) ?: [] as $block) {
            if (preg_match('/^\s*[\'"]?composer\b/', $block) === 1 && preg_match('/target-branch:\s*[\'"]?([\w.\/-]+)/', $block, $m) === 1) {
                $entries[$m[1]] = $block;
            }
        }

        $problems = [];
        foreach ($filament as $branch => [$package, $major]) {
            $next = $major + 1;
            if (! isset($entries[$branch])) {
                $problems[] = "dependabot.yml has no composer entry for {$branch}";

                continue;
            }

            $ignored = false;
            foreach (array_slice(preg_split('/dependency-name:/', $entries[$branch]) ?: [], 1) as $rule) {
                $name = trim((string) strtok(ltrim($rule), "\n"), " '\"");
                $covers = $name === $package || ($name !== '' && str_ends_with($name, '*') && str_starts_with($package, rtrim($name, '*')));
                if ($covers && (str_contains($rule, 'semver-major') || preg_match('/>=\s*'.$next.'(?!\d)/', $rule) === 1)) {
                    $ignored = true;
                }
                // ponytail: a plugin built on another Filament package (filament-analytics-core) ignores that
                // package's next major instead; its major isn't known here, so any ">=" on it counts.
                if ($name !== $package && str_contains($name, 'filament') && ! str_ends_with($name, '*') && str_contains($rule, '>=')) {
                    $ignored = true;
                }
            }

            if (! $ignored) {
                $problems[] = "dependabot.yml lets {$branch} update {$package} to {$next}.x: ignore '>={$next}.0' on its composer entry";
            }
        }

        return $problems;
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
                if (is_string($constraint) && preg_match('/^v?\d+(\.\d+){0,3}(-(stable|rc|beta|alpha|patch|p)\.?\d*)?(@(stable|rc|beta|alpha|dev))?$/i', trim($constraint)) === 1) {
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
