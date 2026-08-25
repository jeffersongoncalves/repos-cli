<?php

namespace App\DTOs;

final class Repo
{
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly string $sshUrl,
        public readonly bool $private,
        public readonly ?string $defaultBranch = null,
    ) {}

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }
}
