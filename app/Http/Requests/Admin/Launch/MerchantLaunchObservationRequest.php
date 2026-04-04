<?php

namespace App\Http\Requests\Admin\Launch;

use App\Models\MerchantLaunchObservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantLaunchObservationRequest extends FormRequest
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
            'type' => ['required', Rule::in(MerchantLaunchObservation::types())],
            'severity' => ['nullable', Rule::in(MerchantLaunchObservation::severities())],
            'source' => ['nullable', 'string', 'max:48'],
            'note' => ['required', 'string', 'max:4000'],
            'feed_generation_id' => ['nullable', 'integer'],
            'feed_item_id' => ['nullable', 'integer'],
            'feedback_import_id' => ['nullable', 'integer'],
            'ops_alert_id' => ['nullable', 'integer'],
        ];
    }
}
