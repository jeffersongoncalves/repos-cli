<?php

namespace App\Enums;

enum GitHost: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Bitbucket = 'bitbucket';

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Gitlab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    public function remoteDomain(): string
    {
        return match ($this) {
            self::Github => 'github.com',
            self::Gitlab => 'gitlab.com',
            self::Bitbucket => 'bitbucket.org',
        };
    }

    public function sshUrl(string $owner, string $repo): string
    {
        return "git@{$this->remoteDomain()}:{$owner}/{$repo}.git";
    }

    public static function fromRemoteDomain(string $domain): ?self
    {
        return match (true) {
            str_contains($domain, 'github.com') => self::Github,
            str_contains($domain, 'gitlab.com') => self::Gitlab,
            str_contains($domain, 'bitbucket.org') => self::Bitbucket,
            default => null,
        };
    }
}
