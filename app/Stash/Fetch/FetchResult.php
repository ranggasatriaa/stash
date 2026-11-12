<?php

namespace App\Stash\Fetch;

final class FetchResult
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $contentType,
        public readonly ?string $charset,
        public readonly string $body,
        public readonly bool $truncated,
    ) {}
}
