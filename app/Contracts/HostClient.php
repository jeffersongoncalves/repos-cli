<?php

namespace App\Contracts;

use App\DTOs\Issue;
use App\DTOs\Repo;

interface HostClient
{
    /**
     * @return Repo[]
     */
    public function listRepos(string $ownerOrOrg): array;

    /**
     * @return Issue[]
     */
    public function listOpenIssues(string $owner, string $repo): array;

    public function currentUsername(): string;
}
