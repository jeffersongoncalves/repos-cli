<?php

use App\Enums\GitHost;

it('maps a known remote domain to its host', function () {
    expect(GitHost::fromRemoteDomain('github.com'))->toBe(GitHost::Github)
        ->and(GitHost::fromRemoteDomain('gitlab.com'))->toBe(GitHost::Gitlab)
        ->and(GitHost::fromRemoteDomain('bitbucket.org'))->toBe(GitHost::Bitbucket)
        ->and(GitHost::fromRemoteDomain('example.com'))->toBeNull();
});

it('builds an ssh clone url', function () {
    expect(GitHost::Github->sshUrl('acme', 'widgets'))->toBe('git@github.com:acme/widgets.git');
});
