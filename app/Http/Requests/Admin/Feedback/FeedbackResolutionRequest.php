<?php

namespace App\Http\Requests\Admin\Feedback;

use App\Models\FeedbackRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedbackResolutionRequest extends FormRequest
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
            'resolution_status' => ['required', Rule::in(FeedbackRecord::resolutionStatuses())],
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
