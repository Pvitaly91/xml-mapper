<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedCutoverRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:5000'],
            'planned_window_starts_at' => ['nullable', 'date'],
            'planned_window_ends_at' => ['nullable', 'date', 'after_or_equal:planned_window_starts_at'],
        ];
    }
}
