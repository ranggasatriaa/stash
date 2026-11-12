<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Stash\Ai\SummaryService;
use App\Stash\Models\SavedView;
use App\Stash\Resources\NavigationCounts;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props. Navigation data is computed with the same rules as the
     * views it links to, and only for authenticated pages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'timezone' => $user->timezone ?: 'UTC',
                    'preferences' => (object) ($user->preferences ?? []),
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                ] : null,
            ],
            'nav' => $user ? fn () => $this->navigation($user) : null,
            'ai' => $user ? fn () => ['enabled' => app(SummaryService::class)->status()['enabled']] : null,
            'limits' => [
                'upload_max_bytes' => (int) config('stash.uploads.max_bytes'),
                'upload_max_video_bytes' => (int) config('stash.videos.max_bytes'),
                'upload_max_pixels' => (int) config('stash.uploads.max_pixels'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function navigation(User $user): array
    {
        $counts = app(NavigationCounts::class);

        return [
            'counts' => $counts->sections($user),
            'projects' => $counts->projects($user),
            'tags' => $counts->tags($user),
            'saved_views' => SavedView::query()->where('user_id', $user->id)->orderBy('position')->orderBy('name')
                ->get(['id', 'name', 'section', 'filters', 'sort'])->toArray(),
        ];
    }
}
