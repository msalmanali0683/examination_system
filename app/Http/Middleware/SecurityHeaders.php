<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cheap, safe browser hardening on every web response: the app is never meant to be shown inside another site's
 * frame, browsers shouldn't second-guess declared content types, and the PHP version needn't be advertised.
 *
 * Deliberately absent: Strict-Transport-Security (browsers remember it for a year, so that belongs to a
 * conscious hosting decision) and a Content-Security-Policy (the Livewire/Alpine pages rely on inline scripts).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Only possible before PHP has started sending output; when it has (scripts, CLI runs), skip rather than warn.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
