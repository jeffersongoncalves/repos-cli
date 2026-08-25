<?php

namespace App\Concerns;

use App\Enums\GitHost;
use InvalidArgumentException;
use JeffersonGoncalves\LaravelZero\Git\GitRemoteParser;
use ValueError;

trait ResolvesHost
{
    protected function resolveHost(?string $hostOption, ?string $target = null): GitHost
    {
        if ($hostOption) {
            try {
                return GitHost::from(strtolower($hostOption));
            } catch (ValueError) {
                throw new InvalidArgumentException("Unknown host '{$hostOption}'. Use github, gitlab, or bitbucket.");
            }
        }

        if ($target !== null && ($remote = GitRemoteParser::parse($target)) !== null) {
            if ($host = GitHost::fromRemoteDomain($remote->host)) {
                return $host;
            }
        }

        throw new InvalidArgumentException('Could not determine the git host. Pass --host=github|gitlab|bitbucket.');
    }
}
