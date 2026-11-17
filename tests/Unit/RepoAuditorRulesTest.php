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

it('compares files ignoring line endings and surrounding whitespace', function () {
    expect(RepoAuditor::sameContent("a: 1\r\nb: 2\r\n", "a: 1\nb: 2"))->toBeTrue()
        ->and(RepoAuditor::sameContent("a: 1\n", "a: 2\n"))->toBeFalse();
});
