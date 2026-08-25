<?php

namespace App\Services;

use App\DTOs\Credentials;
use App\Enums\GitHost;
use JeffersonGoncalves\LaravelZero\Credentials\AbstractAuthService;
use JeffersonGoncalves\LaravelZero\Credentials\CredentialsContract;

class AuthService extends AbstractAuthService
{
    public function load(): ?Credentials
    {
        $credentials = parent::load();

        return $credentials instanceof Credentials ? $credentials : null;
    }

    /**
     * @param  array<string, string>  $data
     */
    public function saveHost(GitHost $host, array $data): Credentials
    {
        $credentials = ($this->load() ?? new Credentials)->withHost($host, $data);

        $this->save($credentials);

        return $credentials;
    }

    protected function appName(): string
    {
        return 'repos-cli';
    }

    protected function fromArray(array $data): CredentialsContract
    {
        return Credentials::fromArray($data);
    }
}
