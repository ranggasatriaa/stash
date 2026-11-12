<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/preferences', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'preferences' => [
                'timezone' => $request->user()->timezone,
                'default_sort' => $request->user()->preference('default_sort', 'newest'),
                'page_size' => (int) $request->user()->preference('page_size', 24),
                'layout' => $request->user()->preference('layout', 'grid'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'default_sort' => ['sometimes', 'string', 'in:newest,oldest,title'],
            'page_size' => ['sometimes', 'integer', 'in:24,48,96'],
            'layout' => ['sometimes', 'string', 'in:grid,table'],
        ]);

        $user = $request->user();
        if (isset($data['timezone'])) {
            $user->timezone = $data['timezone'];
        }
        $prefs = $user->preferences ?? [];
        foreach (['default_sort', 'page_size', 'layout'] as $key) {
            if (array_key_exists($key, $data)) {
                $prefs[$key] = $data[$key];
            }
        }
        $user->preferences = $prefs;
        $user->save();

        if (! $request->boolean('silent')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Preferences saved.']);
        }

        return back();
    }
}
