<?php

namespace App\Http\Requests\Admin\Feedback;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedbackImportRequest extends FormRequest
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
            'format' => ['required', Rule::in(['csv', 'json'])],
            'file' => ['required', 'file', 'max:5120'],
            'generation_id' => [
                'nullable',
                'integer',
                Rule::exists('feed_generations', 'id')->where(fn ($query) => $query->where('feed_profile_id', $feedProfile?->id)),
            ],
            'dry_run' => ['nullable', 'boolean'],
            'confirmation' => [
                'nullable',
                'string',
                Rule::requiredIf(
                    config('feed_mediator.security.require_high_risk_confirmation', false)
                    && ! $this->boolean('dry_run')
                    && $this->routeIs('admin.feed-profiles.feedback.store')
                ),
                Rule::in(['CONFIRM']),
            ],
        ];
    }
}
