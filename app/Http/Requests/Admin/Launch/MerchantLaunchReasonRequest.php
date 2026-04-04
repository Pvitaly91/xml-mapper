<?php

namespace App\Http\Requests\Admin\Launch;

use Illuminate\Foundation\Http\FormRequest;

class MerchantLaunchReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'override_blockers' => ['nullable', 'boolean'],
        ];
    }
}
