<?php

namespace App\Http\Requests\Admin\Ops;

use App\Models\OpsNotificationRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationTestRequest extends FormRequest
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
            'channel' => ['required', Rule::in(OpsNotificationRoute::channels())],
            'target' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
