<?php

namespace App\DTOs;

final class Issue
{
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $author,
        public readonly ?string $updatedAt,
        public readonly string $repo,
    ) {}
}
