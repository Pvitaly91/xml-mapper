<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedReviewNoteRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:5000'],
            'note_type' => ['required', Rule::in(['internal', 'external'])],
            'important' => ['nullable', 'boolean'],
        ];
    }
}
