<?php

use App\Services\Hosts\GithubDeviceAuth;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function makeDeviceAuth(array $responses): GithubDeviceAuth
{
    $handler = HandlerStack::create(new MockHandler($responses));

    return new GithubDeviceAuth(new Client(['handler' => $handler]));
}

it('requests a device code', function () {
    $auth = makeDeviceAuth([
        new Response(200, [], json_encode([
            'device_code' => 'devcode',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://github.com/login/device',
            'expires_in' => 900,
            'interval' => 5,
        ])),
    ]);

    expect($auth->requestDeviceCode())->toMatchArray(['user_code' => 'ABCD-1234']);
});

it('polls through authorization_pending until the token arrives', function () {
    $auth = makeDeviceAuth([
        new Response(200, [], json_encode(['error' => 'authorization_pending'])),
        new Response(200, [], json_encode(['access_token' => 'gho_token', 'refresh_token' => 'ghr_token', 'expires_in' => 28800])),
    ]);

    expect($auth->pollForToken('devcode', 0))->toMatchArray(['access_token' => 'gho_token']);
});

it('throws on a terminal device flow error', function () {
    $auth = makeDeviceAuth([
        new Response(200, [], json_encode(['error' => 'expired_token', 'error_description' => 'The device code has expired.'])),
    ]);

    expect(fn () => $auth->pollForToken('devcode', 0))
        ->toThrow(RuntimeException::class, 'The device code has expired.');
});

it('refreshes an access token', function () {
    $auth = makeDeviceAuth([
        new Response(200, [], json_encode(['access_token' => 'gho_new', 'refresh_token' => 'ghr_new', 'expires_in' => 28800])),
    ]);

    expect($auth->refreshToken('ghr_old'))->toMatchArray(['access_token' => 'gho_new']);
});
