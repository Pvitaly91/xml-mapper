<?php

namespace App\Http\Requests\Admin\MappingPresets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MappingPresetImportRequest extends FormRequest
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
            'preset_json' => ['required', 'json'],
            'collision_strategy' => ['required', Rule::in(['skip_existing', 'overwrite_existing', 'merge_if_safe'])],
        ];
    }
}
