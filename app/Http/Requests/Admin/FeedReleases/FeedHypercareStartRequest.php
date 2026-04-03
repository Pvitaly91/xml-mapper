<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;

class FeedHypercareStartRequest extends FormRequest
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
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
