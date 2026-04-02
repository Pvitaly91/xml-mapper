<?php

namespace App\Http\Requests\Admin\Mappings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValueSuggestionRequest extends FormRequest
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
            'source_attribute_value_ids' => ['nullable', 'array'],
            'source_attribute_value_ids.*' => ['integer', Rule::exists('source_attribute_values', 'id')],
        ];
    }
}
