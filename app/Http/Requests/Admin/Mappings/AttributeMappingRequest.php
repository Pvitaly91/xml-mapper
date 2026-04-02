<?php

namespace App\Http\Requests\Admin\Mappings;

use App\Models\AttributeMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttributeMappingRequest extends FormRequest
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
        $mapping = $this->route('attribute_mapping');

        return [
            'source_category_id' => [
                'nullable',
                'integer',
                Rule::exists('source_categories', 'id')->where(
                    fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id)
                ),
            ],
            'source_attribute_id' => [
                'required',
                'integer',
                Rule::exists('source_attributes', 'id')->where(
                    fn ($query) => $query->where('source_connection_id', $feedProfile->source_connection_id)
                ),
            ],
            'kasta_category_id' => ['nullable', 'integer', Rule::exists('kasta_categories', 'id')],
            'kasta_attribute_id' => ['required', 'integer', Rule::exists('kasta_attributes', 'id')],
            'is_required' => ['nullable', 'boolean'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'use_variant_value' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $feedProfile = $this->route('feed_profile');
            $mapping = $this->route('attribute_mapping');

            $exists = AttributeMapping::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('source_category_id', $this->input('source_category_id'))
                ->where('source_attribute_id', $this->input('source_attribute_id'))
                ->where('kasta_attribute_id', $this->input('kasta_attribute_id'))
                ->when($mapping, fn ($query) => $query->whereKeyNot($mapping->id))
                ->exists();

            if ($exists) {
                $validator->errors()->add('kasta_attribute_id', 'This attribute mapping already exists for the selected scope.');
            }
        });
    }
}
