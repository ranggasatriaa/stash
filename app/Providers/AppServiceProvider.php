<?php

namespace App\Providers;

use App\Stash\Ai\DeepSeekProvider;
use App\Stash\Ai\FakeSummaryProvider;
use App\Stash\Ai\SummaryProvider;
use App\Stash\Fetch\DnsHostResolver;
use App\Stash\Fetch\HostResolver;
use App\Stash\Models\AiRun;
use App\Stash\Models\DataExport;
use App\Stash\Models\DataImport;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Models\SavedView;
use App\Stash\Models\Tag;
use App\Stash\Policies\OwnerPolicy;
use App\Stash\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestContext::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(SummaryProvider::class, function () {
            return match (config('stash.ai.provider')) {
                // Deterministic offline provider for demos; never calls a paid API.
                'fake' => new FakeSummaryProvider(true, 'fake-model'),
                default => new DeepSeekProvider,
            };
        });
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePolicies();
        $this->configureRateLimiting();
        $this->configureTrustedProxies();
    }

    protected function configureTrustedProxies(): void
    {
        $proxies = (string) config('stash.trusted_proxies', '');
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        // No breached-password lookup: the owner password is set via the CLI
        // and Stash makes no third-party calls during authentication.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)->mixedCase()->letters()->numbers()->symbols()
            : Password::min(8),
        );
    }

    protected function configurePolicies(): void
    {
        foreach ([Resource::class, ResourceFile::class, Tag::class, Project::class, SavedView::class, AiRun::class, DataExport::class, DataImport::class] as $model) {
            Gate::policy($model, OwnerPolicy::class);
        }
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'ip:'.$request->ip();

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('api-costly', function (Request $request) {
            return Limit::perMinute(20)->by('costly:'.($request->user()->id ?? $request->ip()));
        });

        RateLimiter::for('saves', function (Request $request) {
            return Limit::perMinute(60)->by('saves:'.($request->user()->id ?? $request->ip()));
        });
    }
}
