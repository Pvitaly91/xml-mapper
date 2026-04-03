<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('feed_mediator.security.headers_enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', (string) config('feed_mediator.security.x_frame_options', 'DENY'));
        $response->headers->set('X-Content-Type-Options', (string) config('feed_mediator.security.x_content_type_options', 'nosniff'));
        $response->headers->set('Referrer-Policy', (string) config('feed_mediator.security.referrer_policy', 'strict-origin-when-cross-origin'));

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (str_contains(mb_strtolower($contentType), 'text/html')) {
            $response->headers->set(
                'Content-Security-Policy',
                (string) config('feed_mediator.security.content_security_policy')
            );
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
