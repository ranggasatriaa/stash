<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Resources\ResourceJson;
use App\Stash\Exceptions\StashException;
use App\Stash\Exceptions\VersionConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Consistent JSON error envelope for /api/* responses.
 *
 * @param  array<string, mixed>  $details
 */
$apiError = function (Request $request, int $status, string $code, string $message, array $details = []) {
    $body = ['error' => array_filter([
        'code' => $code,
        'message' => $message,
        'request_id' => $request->attributes->get('request_id'),
        'details' => $details ?: null,
    ], fn ($v) => $v !== null)];

    return response()->json($body, $status);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(prepend: [AssignRequestId::class], append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [AssignRequestId::class], append: [SecurityHeaders::class]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'idempotent' => EnsureIdempotency::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );

        $exceptions->dontReport([StashException::class, VersionConflict::class]);

        $exceptions->render(function (VersionConflict $e, Request $request) use ($apiError) {
            $current = (new ResourceJson($e->current))->withDetail()->resolve($request);
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $apiError($request, 409, 'version_conflict', $e->getMessage(), [
                    'expected_version' => $e->expected,
                    'current_version' => $e->current->version,
                    'current' => $current,
                ]);
            }

            Inertia::flash('conflict', ['message' => $e->getMessage(), 'current' => $current]);

            return back()->withErrors(['version' => $e->getMessage()]);
        });

        $exceptions->render(function (StashException $e, Request $request) use ($apiError) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $apiError($request, $e->status, $e->errorCode, $e->getMessage(), $e->details);
            }

            if ($e->status === 404) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'That item no longer exists.']);

                return redirect()->route('library');
            }

            $fieldErrors = array_filter($e->details, fn ($v) => is_array($v) && array_is_list($v) && is_string($v[0] ?? null));
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back()->withErrors($fieldErrors ?: ['_error' => $e->getMessage()]);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 422, 'validation_failed', 'The request is invalid.', ['fields' => $e->errors()]);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 401, 'unauthenticated', 'A valid API token is required.');
            }

            return null;
        });

        $exceptions->render(function (MissingAbilityException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 403, 'missing_ability', 'This token is missing the required ability.', ['required' => array_values($e->abilities())]);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 403, 'forbidden', 'This action is not allowed.');
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 429, 'rate_limited', 'Too many requests. Slow down and retry later.')
                    ->withHeaders($e->getHeaders());
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError($request, 404, 'not_found', 'The requested item was not found.');
            }

            return null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($apiError) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Laravel converts authorization exceptions before render callbacks run.
            $previous = $e->getPrevious();
            if ($previous instanceof MissingAbilityException) {
                return $apiError($request, 403, 'missing_ability', 'This token is missing the required ability.', ['required' => array_values($previous->abilities())]);
            }

            $status = $e->getStatusCode();
            [$code, $message] = match ($status) {
                403 => ['forbidden', 'This action is not allowed.'],
                404 => ['not_found', 'The requested item was not found.'],
                405 => ['method_not_allowed', 'Method not allowed.'],
                409 => ['conflict', $e->getMessage() ?: 'Conflict.'],
                410 => ['gone', $e->getMessage() ?: 'This item is no longer available.'],
                413 => ['payload_too_large', 'The request is too large.'],
                415 => ['unsupported_media_type', 'Unsupported media type.'],
                503 => ['unavailable', 'Service unavailable.'],
                default => ['http_'.$status, $e->getMessage() ?: 'Request failed.'],
            };

            return $apiError($request, $status, $code, $message)->withHeaders($e->getHeaders());
        });
    })->create();
