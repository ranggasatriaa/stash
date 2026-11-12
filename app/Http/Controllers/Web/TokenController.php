<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Scoped API token management. The plain-text token is shown exactly once
 * (flashed to the next page load) and is never logged or stored in clear.
 */
class TokenController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = $request->user()->tokens()->orderByDesc('created_at')->get()->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $t->expires_at?->toIso8601ZuluString(),
            'created_at' => $t->created_at?->toIso8601ZuluString(),
            'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
        ]);

        return Inertia::render('settings/tokens', [
            'tokens' => $tokens,
            'abilities' => config('stash.tokens.abilities'),
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $abilities = array_keys((array) config('stash.tokens.abilities'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/\S/'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', $abilities)],
            'expires_in_days' => ['nullable', 'integer', 'in:7,30,90,365'],
        ]);

        $expires = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        $token = $request->user()->createToken(trim($data['name']), array_values(array_unique($data['abilities'])), $expires);

        $activity->record($request->user()->id, 'token.created', 'token', (string) $token->accessToken->getKey(), ['abilities' => $token->accessToken->abilities]);
        Inertia::flash('new_token', ['name' => $token->accessToken->name, 'plain_text' => $token->plainTextToken]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token created. Copy it now — it will not be shown again.']);

        return back();
    }

    public function destroy(Request $request, ActivityLogger $activity, string $tokenId): RedirectResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();
        abort_if($deleted === 0, 404);
        $activity->record($request->user()->id, 'token.revoked', 'token', $tokenId);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token revoked.']);

        return back();
    }
}
