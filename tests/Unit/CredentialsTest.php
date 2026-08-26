<?php

use App\DTOs\Credentials;
use App\Enums\GitHost;

it('round-trips per-host credentials through array form', function () {
    $credentials = (new Credentials)
        ->withHost(GitHost::Github, ['token' => 'gh-token'])
        ->withHost(GitHost::Bitbucket, ['username' => 'alice@example.com', 'app_password' => 'secret']);

    $restored = Credentials::fromArray($credentials->toArray());

    expect($restored->forHost(GitHost::Github))->toBe(['token' => 'gh-token'])
        ->and($restored->forHost(GitHost::Bitbucket))->toBe(['username' => 'alice@example.com', 'app_password' => 'secret'])
        ->and($restored->forHost(GitHost::Gitlab))->toBeNull()
        ->and($restored->isValid())->toBeTrue();
});

it('is invalid when no host has been saved', function () {
    expect((new Credentials)->isValid())->toBeFalse();
});

it('keeps multiple named profiles per host independent', function () {
    $credentials = (new Credentials)
        ->withHost(GitHost::Github, ['token' => 'personal-token'])
        ->withHost(GitHost::Github, ['token' => 'work-token'], 'work');

    expect($credentials->forHost(GitHost::Github))->toBe(['token' => 'personal-token'])
        ->and($credentials->forHost(GitHost::Github, 'work'))->toBe(['token' => 'work-token'])
        ->and($credentials->profilesFor(GitHost::Github))->toBe([
            'default' => ['token' => 'personal-token'],
            'work' => ['token' => 'work-token'],
        ]);
});

it('migrates a pre-profile flat credential to the default profile on load', function () {
    $restored = Credentials::fromArray([
        'hosts' => ['github' => ['token' => 'legacy-token']],
    ]);

    expect($restored->forHost(GitHost::Github))->toBe(['token' => 'legacy-token']);
});

it('drops the host entirely once its last profile is removed', function () {
    $credentials = (new Credentials)->withHost(GitHost::Github, ['token' => 'x']);

    expect($credentials->withoutHost(GitHost::Github)->profilesFor(GitHost::Github))->toBe([]);
});
