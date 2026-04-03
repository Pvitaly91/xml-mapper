<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedFreezeRequest extends FormRequest
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
            'freeze' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmation' => [
                'nullable',
                'string',
                Rule::requiredIf(config('feed_mediator.security.require_high_risk_confirmation', false)),
                Rule::in(['CONFIRM']),
            ],
        ];
    }
}
