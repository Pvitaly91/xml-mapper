<?php

namespace App\Http\Requests\Admin\Launch;

use App\Models\MerchantLaunchTuningAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantLaunchTuningRequest extends FormRequest
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
            'type' => ['required', Rule::in(MerchantLaunchTuningAction::types())],
            'mode' => ['nullable', Rule::in(MerchantLaunchTuningAction::modes())],
            'key' => ['nullable', 'string', 'max:120'],
            'value' => ['nullable'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
