<?php

namespace App\Http\Middleware;

use App\Stash\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a well-formed X-Request-Id or generates one, exposes it to logs,
 * activity events and error bodies, and echoes it on the response.
 */
class AssignRequestId
{
    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $this->context->requestId = $id;
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
