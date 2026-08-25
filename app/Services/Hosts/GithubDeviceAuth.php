<?php

namespace App\Services\Hosts;

use GuzzleHttp\Client;
use RuntimeException;

class GithubDeviceAuth
{
    public const CLIENT_ID = 'Ov23liZS6neNvjyr49rt';

    protected const DEVICE_CODE_URL = 'https://github.com/login/device/code';

    protected const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client;
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     */
    public function requestDeviceCode(string $scope = 'repo'): array
    {
        return $this->post(self::DEVICE_CODE_URL, [
            'client_id' => self::CLIENT_ID,
            'scope' => $scope,
        ]);
    }

    /**
     * Polls until the user finishes authorizing, or GitHub reports a terminal error
     * (denied, expired). Honors 'slow_down' by widening the interval as instructed.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, refresh_token_expires_in?: int}
     */
    public function pollForToken(string $deviceCode, int $interval): array
    {
        while (true) {
            sleep($interval);

            $response = $this->post(self::TOKEN_URL, [
                'client_id' => self::CLIENT_ID,
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
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, refresh_token_expires_in?: int}
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->post(self::TOKEN_URL, [
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! isset($response['access_token'])) {
            throw new RuntimeException($response['error_description'] ?? ($response['error'] ?? 'Could not refresh GitHub token.'));
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
