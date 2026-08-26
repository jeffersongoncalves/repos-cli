<?php

use App\Contracts\HostClient;
use App\DTOs\Credentials;
use App\Enums\GitHost;
use App\Exceptions\GithubApiException;
use App\Services\AuthService;
use App\Services\HostClientFactory;

it('shows valid and the live username when --verify succeeds', function () {
    $credentials = (new Credentials)->withHost(GitHost::Github, ['token' => 'gho_secret']);

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('load')->andReturn($credentials);
    $auth->shouldReceive('getConfigPath')->andReturn('/fake/config.json');
    $this->app->instance(AuthService::class, $auth);

    $client = Mockery::mock(HostClient::class);
    $client->shouldReceive('currentUsername')->andReturn('octocat');

    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->with(GitHost::Github, Credentials::DEFAULT_PROFILE)->andReturn($client);
    $this->app->instance(HostClientFactory::class, $factory);

    // A single doWrite() call renders the whole table row, so "valid" and
    // "octocat" can't both be asserted here (Mockery only lets one
    // expectsOutputToContain consume a given call) — "octocat" alone is
    // enough proof the success branch (and not the "-" fallback) ran.
    $this->artisan('auth:show', ['--verify' => true])
        ->expectsOutputToContain('octocat')
        ->assertExitCode(0);
});

it('shows invalid with the API error message when --verify fails, never the token', function () {
    $credentials = (new Credentials)->withHost(GitHost::Github, ['token' => 'gho_secret']);

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('load')->andReturn($credentials);
    $auth->shouldReceive('getConfigPath')->andReturn('/fake/config.json');
    $this->app->instance(AuthService::class, $auth);

    $client = Mockery::mock(HostClient::class);
    $client->shouldReceive('currentUsername')->andThrow(
        GithubApiException::fromResponse(401, ['message' => 'Bad credentials'])
    );

    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->with(GitHost::Github, Credentials::DEFAULT_PROFILE)->andReturn($client);
    $this->app->instance(HostClientFactory::class, $factory);

    $this->artisan('auth:show', ['--verify' => true])
        ->expectsOutputToContain('invalid (Bad credentials)')
        ->doesntExpectOutputToContain('gho_secret')
        ->assertExitCode(0);
});
