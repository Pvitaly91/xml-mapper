<?php

namespace App\Http\Middleware;

use App\Services\Admin\CurrentAdminShopResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAdminShopContextMiddleware
{
    public function __construct(
        private readonly CurrentAdminShopResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = $this->resolver->resolve($request);

        if ($shop !== null) {
            $request->attributes->set('admin_shop', $shop);
        }

        return $next($request);
    }
}
