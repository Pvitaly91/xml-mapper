<?php

namespace App\Http\Middleware;

use App\Services\Access\AdminAccessService;
use App\Services\Access\AdminRoutePermissionResolver;
use App\Services\Admin\CurrentAdminShopResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermissionMiddleware
{
    public function __construct(
        private readonly AdminRoutePermissionResolver $permissionResolver,
        private readonly AdminAccessService $accessService,
        private readonly CurrentAdminShopResolver $shopResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $permission = $this->permissionResolver->resolve($request->route()?->getName(), $request->getMethod());

        if ($permission === null) {
            return $next($request);
        }

        $shop = $request->attributes->get('admin_shop') ?: $this->shopResolver->resolve($request);

        if (! $this->accessService->can($user, $permission, $shop)) {
            abort(403, 'You do not have permission to access this area.');
        }

        $request->attributes->set('admin_permission', $permission);

        return $next($request);
    }
}
