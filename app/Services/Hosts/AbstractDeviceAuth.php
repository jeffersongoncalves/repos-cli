<?php

namespace App\Services\Hosts;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * OAuth 2.0 Device Authorization Grant (RFC 8628), shared by every host that
 * implements it the same way GitHub does (GitLab included): request a device
 * code, poll the token endpoint until the user authorizes, refresh later.
 */
abstract class AbstractDeviceAuth
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client;
    }

    abstract protected function deviceCodeUrl(): string;

    abstract protected function tokenUrl(): string;

    abstract protected function clientId(): string;

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     */
    public function requestDeviceCode(string $scope): array
    {
        return $this->post($this->deviceCodeUrl(), [
            'client_id' => $this->clientId(),
            'scope' => $scope,
        ]);
    }

    /**
     * Polls until the user finishes authorizing, or the host reports a terminal error
     * (denied, expired). Honors 'slow_down' by widening the interval as instructed.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function pollForToken(string $deviceCode, int $interval): array
    {
        while (true) {
            sleep($interval);

            $response = $this->post($this->tokenUrl(), [
                'client_id' => $this->clientId(),
                'device_code' => $deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ]);

            if (isset($response['access_token'])) {
                return $response;
            }

            $interval = match ($response['error'] ?? null) {
                'authorization_pending' => $interval,
                'slow_down' => (int) ($response['interval'] ?? $interval + 5),
                default => throw new RuntimeException($response['error_description'] ?? ($response['error'] ?? 'Device authorization failed.')),
            };
        }
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->post($this->tokenUrl(), [
            'client_id' => $this->clientId(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! isset($response['access_token'])) {
            throw new RuntimeException($response['error_description'] ?? ($response['error'] ?? 'Could not refresh token.'));
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    protected function post(string $url, array $data): array
    {
        $response = $this->client->post($url, [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
