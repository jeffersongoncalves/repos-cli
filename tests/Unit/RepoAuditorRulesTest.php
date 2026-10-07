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

it('compares files ignoring line endings and surrounding whitespace', function () {
    expect(RepoAuditor::sameContent("a: 1\r\nb: 2\r\n", "a: 1\nb: 2"))->toBeTrue()
        ->and(RepoAuditor::sameContent("a: 1\n", "a: 2\n"))->toBeFalse();
});
