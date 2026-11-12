<?php

namespace App\Stash\Fetch;

interface HostResolver
{
    /**
     * Resolve a hostname to all of its IPv4/IPv6 addresses.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
