<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LiveTimelineFilterRequest extends FormRequest
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
            'event_type' => ['nullable', Rule::in(['release_event', 'sync_log', 'smoke_check', 'first_pull', 'ops_run'])],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
