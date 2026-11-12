<?php

namespace App\Http\Middleware;

use App\Stash\Exceptions\StashException;
use App\Stash\Idempotency\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies Idempotency-Key semantics when the header is present. Successful
 * JSON responses are stored and replayed with `Idempotent-Replayed: true`.
 */
class EnsureIdempotency
{
    public function __construct(private readonly IdempotencyService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        $user = $request->user();
        if ($key === null || $user === null) {
            return $next($request);
        }

        $key = trim($key);
        if ($key === '' || strlen($key) > 255 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            throw new StashException('invalid_idempotency_key', 'Idempotency-Key must be 1–255 visible ASCII characters.', 422);
        }

        $token = $user->currentAccessToken();
        $scope = $token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'session';
        $hash = $this->service->fingerprint($request);

        $existing = $this->service->claim($user->id, $scope, $key, $hash, $request);
        if ($existing) {
            return response()->json($existing->response_body, (int) $existing->response_status)
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->service->release($user->id, $scope, $key);
            throw $e;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $response instanceof JsonResponse) {
            $body = $response->getData(true);
            $this->service->complete($user->id, $scope, $key, $response->getStatusCode(), is_array($body) ? $body : null, data_get($body, 'data.id'));
        } else {
            $this->service->release($user->id, $scope, $key);
        }

        return $response;
    }
}
