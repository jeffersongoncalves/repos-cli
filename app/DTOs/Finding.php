<?php

namespace App\DTOs;

/**
 * One problem found by `repos audit` in one repository.
 */
final class Finding
{
    public function __construct(
        public readonly string $repo,
        public readonly string $check,
        public readonly string $message,
    ) {}

    /**
     * @return array{repo: string, check: string, message: string}
     */
    public function toArray(): array
    {
        return ['repo' => $this->repo, 'check' => $this->check, 'message' => $this->message];
    }
}
