<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Services\Auth\AdminAuthenticationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()?->isAdmin()) {
            return redirect()->route(app(\App\Services\Auth\AdminAuthPolicyService::class)->loginRedirectRoute($request->user()));
        }

        return view('admin.auth.login');
    }

    public function store(AdminLoginRequest $request, AdminAuthenticationService $service): RedirectResponse
    {
        try {
            $result = $service->attempt($request, $request->validated());
        } catch (\Illuminate\Auth\AuthenticationException $exception) {
            throw ValidationException::withMessages([
                'email' => $exception->getMessage(),
            ]);
        }

        return redirect()->intended(route($result->redirectRoute))
            ->with('status', $result->message);
    }

    public function destroy(Request $request, AdminAuthenticationService $service): RedirectResponse
    {
        $service->logout($request);

        return redirect()->route('login')
            ->with('status', 'Admin session closed.');
    }
}
