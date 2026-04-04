<?php

namespace App\Http\Requests\Admin\Launch;

use App\Models\MerchantLaunchDefect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantLaunchDefectUpdateRequest extends FormRequest
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
            'severity' => ['nullable', Rule::in(MerchantLaunchDefect::severities())],
            'status' => ['required', Rule::in(MerchantLaunchDefect::statuses())],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
