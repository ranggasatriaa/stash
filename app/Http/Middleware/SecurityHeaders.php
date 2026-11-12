<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers. The CSP allows same-origin scripts plus a
 * per-request nonce (and Vite's dev server locally) and blocks framing,
 * plugins and remote images: previews are always served by Stash itself.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Private app: never indexed, cached, snippeted or used for AI training.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai');

        if (! $response->headers->has('Content-Security-Policy')) {
            $dev = '';
            if (app()->environment('local') && is_file(public_path('hot'))) {
                $hot = trim((string) file_get_contents(public_path('hot')));
                $ws = preg_replace('/^http/', 'ws', $hot);
                $dev = " {$hot} {$ws}";
            }
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'{$dev}",
                "style-src 'self' 'unsafe-inline'{$dev}",
                "img-src 'self' data: blob:",
                "media-src 'self' blob:",
                "font-src 'self' data:{$dev}",
                "connect-src 'self'{$dev}",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
                "form-action 'self'",
            ]));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
