<?php

namespace App\Http\Requests\Admin\Auth;

use App\Services\Auth\AdminAuthPolicyService;
use Illuminate\Foundation\Http\FormRequest;

class AdminPasswordResetRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', app(AdminAuthPolicyService::class)->passwordRule()],
        ];
    }
}
