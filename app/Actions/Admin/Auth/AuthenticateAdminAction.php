<?php

namespace App\Actions\Admin\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateAdminAction
{
    /**
     * @param  array{email:string,password:string,remember?:bool}  $credentials
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, array $credentials): void
    {
        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            throw new AuthenticationException('Invalid admin credentials.');
        }

        $request->session()->regenerate();

        if (! $request->user()?->isAdmin()) {
            Auth::logout();
            throw new AuthenticationException('This account does not have admin access.');
        }
    }
}
