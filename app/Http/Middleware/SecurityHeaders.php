<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response.
 *
 * SAMEORIGIN rather than DENY: Account Settings prints the recovery codes
 * through a same-origin iframe. No Content-Security-Policy yet: the scan
 * workspace's script is inline, so a policy needs Vite nonces first.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'same-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
