<?php

namespace App\Http\Requests\Admin\Auth;

use App\Services\Auth\AdminAuthPolicyService;
use Illuminate\Foundation\Http\FormRequest;

class AdminInviteAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'confirmed', app(AdminAuthPolicyService::class)->passwordRule()],
        ];
    }
}
