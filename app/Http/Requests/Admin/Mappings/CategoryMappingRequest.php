<?php

namespace App\Http\Requests\Admin\Mappings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryMappingRequest extends FormRequest
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
        $mapping = $this->route('category_mapping');

        return [
            'source_category_id' => [
                'required',
                'integer',
                Rule::exists('source_categories', 'id')->where(
                    fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id)
                ),
                Rule::unique('category_mappings', 'source_category_id')
                    ->where(fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
                    ->ignore($mapping?->id),
            ],
            'kasta_category_id' => ['required', 'integer', Rule::exists('kasta_categories', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
