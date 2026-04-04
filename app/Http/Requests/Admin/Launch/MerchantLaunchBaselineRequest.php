<?php

namespace App\Http\Requests\Admin\Launch;

use Illuminate\Foundation\Http\FormRequest;

class MerchantLaunchBaselineRequest extends FormRequest
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
            'expected_ready_items' => ['nullable', 'integer', 'min:0'],
            'expected_published_count' => ['nullable', 'integer', 'min:0'],
            'expected_first_pull_latency_ms' => ['nullable', 'integer', 'min:0'],
            'expected_feedback_total' => ['nullable', 'integer', 'min:0'],
            'expected_rejection_total' => ['nullable', 'integer', 'min:0'],
            'expected_sync_freshness_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
