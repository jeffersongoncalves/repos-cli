<?php

use App\Services\RepoAuditor;

it('expects the highest N.x branch on multi-branch repos', function () {
    expect(RepoAuditor::expectedDefaultBranch(['1.x', '3.x', '2.x', 'main']))->toBe('3.x')
        ->and(RepoAuditor::expectedDefaultBranch(['9.x', '10.x']))->toBe('10.x');
});

it('expects main everywhere else', function () {
    expect(RepoAuditor::expectedDefaultBranch(['main', 'feature/x']))->toBe('main')
        ->and(RepoAuditor::expectedDefaultBranch([]))->toBe('main');
});

it('lists migration stubs the service provider does not register', function () {
    $stubs = ['create_a_table.php.stub', 'create_b_table.php.stub', 'add_c_to_a_table.php.stub', 'README.md'];
    $provider = <<<'PHP'
        $package->name('x')
            ->hasMigration('create_a_table')
            ->hasMigrations([
                "create_b_table.php",
            ]);
        PHP;

    expect(RepoAuditor::unregisteredMigrations(array_slice($stubs, 0, 3), $provider))->toBe(['add_c_to_a_table'])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], "const MIGRATIONS = ['create_a_table'];\n->hasMigrations(static::MIGRATIONS)"))->toBe([])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], '$package->discoversMigrations();'))->toBe([])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], '$package->discoversMigrations ();'))->toBe([])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], "// \$package->discoversMigrations();\n/* 'create_a_table' */"))->toBe(['create_a_table'])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], "\$x = 'discoversMigrations()';"))->toBe(['create_a_table'])
        ->and(RepoAuditor::unregisteredMigrations(['create_a_table.php.stub'], ''))->toBe(['create_a_table']);
});

it('lists requirements pinned to an exact version', function () {
    $composer = json_encode([
        'require' => ['php' => '^8.2', 'acme/a' => '1.0', 'acme/b' => '^2.1', 'acme/c' => 'v3.0.1', 'acme/d' => '~1.0', 'acme/e' => '*', 'acme/f' => 'dev-main'],
        'require-dev' => ['acme/g' => '2.1.3', 'acme/h' => '>=1.0', 'acme/i' => '2.1.3-stable', 'acme/j' => '>=2.1.3-stable', 'acme/k' => '1.0.0-RC2', 'acme/l' => '1.0.0-RC1@RC', 'acme/m' => '^1.0@beta'],
    ]);

    expect(RepoAuditor::pinnedConstraints($composer))->toBe(['acme/a 1.0', 'acme/c v3.0.1', 'acme/g 2.1.3', 'acme/i 2.1.3-stable', 'acme/k 1.0.0-RC2', 'acme/l 1.0.0-RC1@RC'])
        ->and(RepoAuditor::pinnedConstraints('{}'))->toBe([])
        ->and(RepoAuditor::pinnedConstraints('not json'))->toBe([]);
});

it('reads the Filament package and major a branch requires', function () {
    expect(RepoAuditor::filamentRequirement('{"require":{"filament/support":"^4.0","filament/filament":"^5.0"}}'))->toBe(['filament/filament', 5])
        ->and(RepoAuditor::filamentRequirement('{"require":{"filament/tables":"~3.2"}}'))->toBe(['filament/tables', 3])
        ->and(RepoAuditor::filamentRequirement('{"require":{"acme/analytics-core":"^2.0"}}'))->toBeNull()
        ->and(RepoAuditor::filamentRequirement(''))->toBeNull();
});

it('matches the README compatibility table against the version branches', function () {
    $filament = ['1.x' => ['filament/filament', 3], '2.x' => ['filament/filament', 4], '3.x' => ['filament/filament', 5]];
    $table = "| Branch | Filament | Package version |\n|---|---|---|\n| **1.x** | 3.x | `^1.0` |\n| 2.x | 4.x | `^2.0` |\n";

    expect(RepoAuditor::compatProblems($table.'| 3.x | 5.x | `^3.0` |', $filament))->toBe([])
        ->and(RepoAuditor::compatProblems($table, $filament))->toBe(['README compatibility table has no row for 3.x (Filament 5)'])
        ->and(RepoAuditor::compatProblems($table.'| 3.x | 4.x | `^3.0` |', $filament))
        ->toBe(['README says 3.x is for "4.x | ^3.0", but its composer.json requires Filament 5'])
        ->and(RepoAuditor::compatProblems($table.'| 3.x | 5.x | `^3.0` |'."\n| 4.x | 6.x | `^4.0` |", $filament))
        ->toBe(['README compatibility table lists 4.x, which is not a version branch'])
        ->and(RepoAuditor::compatProblems("| Filament | Branch |\n|---|---|\n| v3 | `1.x` |\n| Filament v4 | [2.x](https://github.com/a/b/tree/2.x) |\n| ^5.0 | 3.x |", $filament))->toBe([])
        ->and(RepoAuditor::compatProblems('# Widget', $filament))->toBe(['README has no compatibility table (version branch → Filament)']);
});

it('expects each version branch to ignore the next Filament major in dependabot.yml', function () {
    $entry = fn (string $branch, string $ignore) => "  - package-ecosystem: github-actions\n    target-branch: {$branch}\n"
        ."  - package-ecosystem: composer\n    directory: /\n    target-branch: {$branch}\n{$ignore}";
    $rule = fn (string $name, string $versions) => "    ignore:\n      - dependency-name: {$name}\n        versions:\n          - '{$versions}'\n";
    $filament = ['1.x' => ['filament/filament', 3], '2.x' => ['filament/filament', 4]];

    expect(RepoAuditor::dependabotIgnoreProblems($entry('1.x', $rule('filament/filament', '>=4.0')).$entry('2.x', $rule('filament/*', '>=5.0')), $filament))->toBe([])
        ->and(RepoAuditor::dependabotIgnoreProblems($entry('1.x', $rule('filament/filament', '>=5.0')).$entry('2.x', ''), $filament))->toBe([
            "dependabot.yml lets 1.x update filament/filament to 4.x: ignore '>=4.0' on its composer entry",
            "dependabot.yml lets 2.x update filament/filament to 5.x: ignore '>=5.0' on its composer entry",
        ])
        ->and(RepoAuditor::dependabotIgnoreProblems($entry('1.x', $rule('acme/filament-analytics-core', '>=2.0')), ['1.x' => ['filament/filament', 3]]))->toBe([])
        ->and(RepoAuditor::dependabotIgnoreProblems($entry('1.x', "    ignore:\n      - dependency-name: '*'\n        update-types: ['version-update:semver-major']\n"), ['1.x' => ['filament/filament', 3]]))->toBe([])
        ->and(RepoAuditor::dependabotIgnoreProblems($entry('1.x', ''), ['3.x' => ['filament/filament', 5]]))->toBe(['dependabot.yml has no composer entry for 3.x']);
});

it('finds the FilaKit base of a starter kit by name', function () {
    expect(RepoAuditor::kitBase('mfakitv5'))->toBe('filakitv5')
        ->and(RepoAuditor::kitBase('helpdeskkitv4'))->toBe('filakitv4')
        ->and(RepoAuditor::kitBase('editorialthemev3'))->toBe('filakit')
        ->and(RepoAuditor::kitBase('teamkit'))->toBe('filakit')
        ->and(RepoAuditor::kitBase('filakitv5'))->toBeNull()
        ->and(RepoAuditor::kitBase('filament-ban'))->toBeNull();
});

it('lists the shared kit files that differ from the base', function () {
    $base = ['artisan' => 'a', 'config/cache.php' => 'b', 'phpunit.xml' => 'c', 'app/Models/User.php' => 'd'];
    $kit = ['artisan' => 'a', 'config/cache.php' => 'x', 'app/Models/User.php' => 'changed'];

    expect(RepoAuditor::kitDrift($kit, $base))->toBe(['phpunit.xml (missing)', 'config/cache.php'])
        ->and(RepoAuditor::kitDrift($base, $base))->toBe([]);
});

it('lists own packages locked behind their newest release of the same major', function () {
    $lock = json_encode([
        'packages' => [
            ['name' => 'acme/laravel-a', 'version' => '1.2.0'],
            ['name' => 'acme/filament-b', 'version' => '3.0.1'],
            ['name' => 'acme/laravel-c', 'version' => 'dev-main'],
            ['name' => 'other/lib', 'version' => '1.0.0'],
        ],
        'packages-dev' => [['name' => 'acme/tool', 'version' => 'v2.0.0']],
    ]);
    $released = [
        'acme/laravel-a' => ['2.0.0', '1.3.1', '1.3.0', '1.2.0', '1.4.0-beta1'],
        'acme/filament-b' => ['3.0.1', '2.9.0'],
        'acme/tool' => ['v2.1.0', 'v2.0.0'],
    ];

    expect(RepoAuditor::staleOwnPackages($lock, 'Acme', fn (string $p) => $released[$p] ?? []))
        ->toBe(['acme/laravel-a 1.2.0 → 1.3.1', 'acme/tool v2.0.0 → v2.1.0'])
        ->and(RepoAuditor::staleOwnPackages('', 'acme', fn () => []))->toBe([]);
});

it('compares files ignoring line endings and surrounding whitespace', function () {
    expect(RepoAuditor::sameContent("a: 1\r\nb: 2\r\n", "a: 1\nb: 2"))->toBeTrue()
        ->and(RepoAuditor::sameContent("a: 1\n", "a: 2\n"))->toBeFalse();
});
