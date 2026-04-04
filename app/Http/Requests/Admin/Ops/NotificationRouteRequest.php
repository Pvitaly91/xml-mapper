<?php

namespace App\Http\Requests\Admin\Ops;

use App\Models\OpsNotificationRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationRouteRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(OpsNotificationRoute::scopes())],
            'channel' => ['required', Rule::in(OpsNotificationRoute::channels())],
            'event_family' => ['nullable', Rule::in(OpsNotificationRoute::eventFamilies())],
            'event_type' => ['nullable', 'string', 'max:120'],
            'minimum_severity' => ['nullable', 'string', 'max:16'],
            'feed_profile_id' => ['nullable', 'integer'],
            'target_value' => ['nullable', 'string', 'max:4000'],
            'enabled' => ['nullable', 'boolean'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'quiet_hours_timezone' => ['nullable', 'string', 'max:64'],
            'muted_until' => ['nullable', 'date'],
            'suppression_window_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'repeat_interval_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'escalate_after_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
