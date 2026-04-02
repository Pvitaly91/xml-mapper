<?php

namespace App\Http\Requests\Admin\Mappings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValueMappingRequest extends FormRequest
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
        $attributeMapping = $this->route('attribute_mapping');
        $valueMapping = $this->route('value_mapping');

        return [
            'source_attribute_value_id' => ['nullable', 'integer', Rule::exists('source_attribute_values', 'id')],
            'source_raw_value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('value_mappings', 'source_raw_value')
                    ->where(fn ($query) => $query->where('attribute_mapping_id', $attributeMapping->id))
                    ->ignore($valueMapping?->id),
            ],
            'kasta_attribute_value_id' => ['nullable', 'integer', Rule::exists('kasta_attribute_values', 'id')],
            'target_value' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->filled('kasta_attribute_value_id') && ! $this->filled('target_value')) {
                $validator->errors()->add('target_value', 'Select a Kasta value or provide a manual target value.');
            }
        });
    }
}
