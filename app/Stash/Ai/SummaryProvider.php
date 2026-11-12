<?php

namespace App\Stash\Ai;

interface SummaryProvider
{
    public function name(): string;

    public function model(): string;

    /** True when credentials and model are configured. */
    public function isConfigured(): bool;

    /**
     * Summarize untrusted text. Implementations must treat the text as data,
     * never as instructions.
     *
     * @throws ProviderError
     */
    public function summarize(string $text, string $sourceKind, int $maxOutputTokens): SummaryResponse;
}
