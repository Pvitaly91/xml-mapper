<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;

class FeedPreviewLinkRequest extends FormRequest
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
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
