<?php

namespace App\Http\Middleware;

use App\Services\Ops\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function __construct(
        private readonly CorrelationContext $correlationContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('feed_mediator.observability.correlation_header', 'X-Correlation-ID');
        $correlationId = $this->correlationContext->ensure($request->headers->get($header));
        $request->attributes->set('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set($header, $correlationId);

        return $response;
    }
}
