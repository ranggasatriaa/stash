<?php

namespace App\Stash\Fetch;

use RuntimeException;

/**
 * A safe fetch failed. `category` is a sanitized, user-presentable error
 * class: timeout, connection, dns, http_status, content_type, too_large,
 * too_many_redirects.
 */
final class FetchFailedException extends RuntimeException
{
    public function __construct(public readonly string $category, string $message = '', public readonly ?int $status = null)
    {
        parent::__construct($message !== '' ? $message : "Fetch failed ({$category}).");
    }
}
