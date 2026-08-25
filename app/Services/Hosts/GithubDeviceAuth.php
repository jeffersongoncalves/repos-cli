<?php

namespace App\Services\Hosts;

class GithubDeviceAuth extends AbstractDeviceAuth
{
    public const CLIENT_ID = 'Ov23liZS6neNvjyr49rt';

    public function requestDeviceCode(string $scope = 'repo'): array
    {
        return parent::requestDeviceCode($scope);
    }

    protected function deviceCodeUrl(): string
    {
        return 'https://github.com/login/device/code';
    }

    protected function tokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function clientId(): string
    {
        return self::CLIENT_ID;
    }
}
