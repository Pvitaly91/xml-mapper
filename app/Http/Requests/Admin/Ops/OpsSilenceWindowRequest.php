<?php

namespace App\Http\Requests\Admin\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpsSilenceWindowRequest extends FormRequest
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'severity' => ['nullable', Rule::in(OpsAlert::severities())],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
