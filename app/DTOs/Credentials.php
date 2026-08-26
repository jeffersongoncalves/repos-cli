<?php

namespace App\DTOs;

use App\Enums\GitHost;
use JeffersonGoncalves\LaravelZero\Credentials\CredentialsContract;

final class Credentials implements CredentialsContract
{
    public const DEFAULT_PROFILE = 'default';

    /**
     * @param  array<string, array<string, array<string, string>>>  $hosts  host => profile => credential data
     */
    public function __construct(
        public readonly array $hosts = [],
    ) {}

    /**
     * Accepts both the current { host: { profile: {...} } } shape and the
     * pre-profile { host: {...} } shape, treating a legacy flat credential
     * as the "default" profile. Migrates on the next save.
     */
    public static function fromArray(array $data): static
    {
        $hosts = array_map(
            fn (array $profiles) => self::isFlatCredential($profiles)
                ? [self::DEFAULT_PROFILE => $profiles]
                : $profiles,
            $data['hosts'] ?? [],
        );

        return new self($hosts);
    }

    /**
     * A legacy single-profile credential has only string leaves
     * (token/username/...); a profile map has array leaves.
     */
    private static function isFlatCredential(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
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
    public function forHost(GitHost $host, string $profile = self::DEFAULT_PROFILE): ?array
    {
        return $this->hosts[$host->value][$profile] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function profilesFor(GitHost $host): array
    {
        return $this->hosts[$host->value] ?? [];
    }

    public function withHost(GitHost $host, array $data, string $profile = self::DEFAULT_PROFILE): self
    {
        $hosts = $this->hosts;
        $hosts[$host->value][$profile] = $data;

        return new self($hosts);
    }

    public function withoutHost(GitHost $host, string $profile = self::DEFAULT_PROFILE): self
    {
        $hosts = $this->hosts;
        unset($hosts[$host->value][$profile]);

        if (($hosts[$host->value] ?? []) === []) {
            unset($hosts[$host->value]);
        }

        return new self($hosts);
    }
}
