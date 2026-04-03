<?php

namespace App\Http\Requests\Admin\FeedReleases;

use App\Models\FeedGenerationSignoff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedSignoffRequest extends FormRequest
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
            'status' => ['required', Rule::in(FeedGenerationSignoff::statuses())],
            'reviewer_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'reason' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf($this->input('status') === FeedGenerationSignoff::STATUS_REJECTED),
            ],
        ];
    }
}
