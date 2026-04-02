<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedPublishRequest extends FormRequest
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
        $feedProfile = $this->route('feed_profile');

        return [
            'generation_id' => [
                'nullable',
                'integer',
                Rule::exists('feed_generations', 'id')->where(fn ($query) => $query->where('feed_profile_id', $feedProfile?->id)),
            ],
            'force_publish' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->boolean('force_publish'))],
        ];
    }
}
