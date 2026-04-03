<?php

namespace App\Http\Requests\Admin\FeedReleases;

use Illuminate\Foundation\Http\FormRequest;

class FeedHypercareCloseRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
