<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;

class FeedRehearsalRequest extends FormRequest
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
            'with_sync' => ['nullable', 'boolean'],
            'with_build' => ['nullable', 'boolean'],
            'with_preview' => ['nullable', 'boolean'],
            'with_smoke' => ['nullable', 'boolean'],
            'with_rollback_check' => ['nullable', 'boolean'],
        ];
    }
}
