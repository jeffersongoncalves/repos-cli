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
