<?php

namespace App\DTOs;

use App\Enums\GitHost;
use JeffersonGoncalves\LaravelZero\Credentials\CredentialsContract;

final class Credentials implements CredentialsContract
{
    /**
     * @param  array<string, array<string, string>>  $hosts  keyed by GitHost::value
     */
    public function __construct(
        public readonly array $hosts = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self($data['hosts'] ?? []);
    }

    public function toArray(): array
    {
        return ['hosts' => $this->hosts];
    }

    public function isValid(): bool
    {
        return $this->hosts !== [];
    }

    /**
     * @return array<string, string>|null
     */
    public function forHost(GitHost $host): ?array
    {
        return $this->hosts[$host->value] ?? null;
    }

    public function withHost(GitHost $host, array $data): self
    {
        $hosts = $this->hosts;
        $hosts[$host->value] = $data;

        return new self($hosts);
    }

    public function withoutHost(GitHost $host): self
    {
        $hosts = $this->hosts;
        unset($hosts[$host->value]);

        return new self($hosts);
    }
}
