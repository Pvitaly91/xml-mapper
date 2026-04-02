<?php

namespace App\Http\Requests\Admin\Mappings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttributeSuggestionRequest extends FormRequest
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
            'source_category_id' => [
                'required',
                'integer',
                Rule::exists('source_categories', 'id')->where(
                    fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id)
                ),
            ],
            'source_attribute_ids' => ['nullable', 'array'],
            'source_attribute_ids.*' => [
                'integer',
                Rule::exists('source_attributes', 'id')->where(
                    fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id)
                ),
            ],
        ];
    }
}
