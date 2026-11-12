<?php

namespace App\Console\Commands;

use App\Stash\Ai\SummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies the optional DeepSeek configuration by listing models
 * (GET /models, no tokens spent). Never prints the API key.
 */
class AiCheck extends Command
{
    protected $signature = 'stash:ai-check';

    protected $description = 'Check the optional AI configuration (lists provider models; spends no tokens)';

    public function handle(SummaryService $summaries): int
    {
        $status = $summaries->status();
        $this->line('AI_ENABLED: '.(config('stash.ai.enabled') ? 'true' : 'false'));
        $this->line('Configured: '.($status['configured'] ? 'yes' : 'no'));
        $this->line('Monthly token limit: '.number_format((int) config('stash.ai.monthly_token_limit')));

        if (! $status['configured']) {
            $this->warn((string) $status['reason']);

            return self::SUCCESS;
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.deepseek.base_url'), '/'))
                ->withToken((string) config('services.deepseek.api_key'))
                ->acceptJson()->timeout(15)->get('/models');
        } catch (Throwable) {
            $this->error('Could not reach the provider.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Provider returned HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $data = $response->json('data', []);
        $models = is_array($data) ? array_values(array_filter(array_column($data, 'id'))) : [];
        $this->line('Available models: '.implode(', ', $models));
        $configured = (string) config('services.deepseek.model');
        if (! in_array($configured, $models, true)) {
            $this->error("DEEPSEEK_MODEL={$configured} is not in the provider's model list.");

            return self::FAILURE;
        }

        $this->info("DEEPSEEK_MODEL={$configured} is available.".($status['enabled'] ? '' : ' Set AI_ENABLED=true to turn summaries on.'));

        return self::SUCCESS;
    }
}
