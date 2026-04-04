<?php

namespace App\Data\Auth;

class LoginResult
{
    public function __construct(
        public readonly string $redirectRoute,
        public readonly ?string $message = null,
    ) {}
}
