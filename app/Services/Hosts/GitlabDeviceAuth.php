<?php

namespace App\Services\Hosts;

class GitlabDeviceAuth extends AbstractDeviceAuth
{
    public const CLIENT_ID = 'c6a86bd418c9e013bdcc787620a87f285030bddeba2990379c7cf3a39a479dea';

    public function requestDeviceCode(string $scope = 'read_api'): array
    {
        return parent::requestDeviceCode($scope);
    }

    protected function deviceCodeUrl(): string
    {
        return 'https://gitlab.com/oauth/authorize_device';
    }

    protected function tokenUrl(): string
    {
        return 'https://gitlab.com/oauth/token';
    }

    protected function clientId(): string
    {
        return self::CLIENT_ID;
    }
}
