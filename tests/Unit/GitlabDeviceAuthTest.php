<?php

use App\Services\Hosts\GitlabDeviceAuth;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function makeGitlabDeviceAuth(array $responses): GitlabDeviceAuth
{
    $handler = HandlerStack::create(new MockHandler($responses));

    return new GitlabDeviceAuth(new Client(['handler' => $handler]));
}

it('requests a device code', function () {
    $auth = makeGitlabDeviceAuth([
        new Response(200, [], json_encode([
            'device_code' => 'devcode',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://gitlab.com/oauth/device',
            'expires_in' => 900,
            'interval' => 5,
        ])),
    ]);

    expect($auth->requestDeviceCode())->toMatchArray(['user_code' => 'ABCD-1234']);
});

it('refreshes an access token', function () {
    $auth = makeGitlabDeviceAuth([
        new Response(200, [], json_encode(['access_token' => 'glpat_new', 'refresh_token' => 'glrt_new', 'expires_in' => 7200])),
    ]);

    expect($auth->refreshToken('glrt_old'))->toMatchArray(['access_token' => 'glpat_new']);
});

it('polls through a 400 authorization_pending response, unlike GitHub which uses 200', function () {
    $auth = makeGitlabDeviceAuth([
        new Response(400, [], json_encode(['error' => 'authorization_pending'])),
        new Response(200, [], json_encode(['access_token' => 'glpat_new', 'refresh_token' => 'glrt_new', 'expires_in' => 7200])),
    ]);

    expect($auth->pollForToken('devcode', 0))->toMatchArray(['access_token' => 'glpat_new']);
});
