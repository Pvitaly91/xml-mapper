<?php

namespace App\Http\Requests\Admin\Launch;

use App\Models\MerchantLaunchDefect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantLaunchDefectRequest extends FormRequest
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
            'type' => ['required', Rule::in(MerchantLaunchDefect::types())],
            'severity' => ['nullable', Rule::in(MerchantLaunchDefect::severities())],
            'status' => ['nullable', Rule::in(MerchantLaunchDefect::statuses())],
            'title' => ['nullable', 'string', 'max:160'],
            'note' => ['required', 'string', 'max:4000'],
            'merchant_launch_observation_id' => ['nullable', 'integer'],
            'feed_generation_id' => ['nullable', 'integer'],
            'feed_item_id' => ['nullable', 'integer'],
            'feedback_record_id' => ['nullable', 'integer'],
            'ops_alert_id' => ['nullable', 'integer'],
        ];
    }
}
