<?php

namespace App\Http\Requests\Admin\FeedItems;

use Illuminate\Foundation\Http\FormRequest;

class FeedItemOverrideRequest extends FormRequest
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
            'is_enabled' => ['required', 'boolean'],
            'excluded_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
