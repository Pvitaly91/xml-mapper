<?php

namespace App\Http\Requests\Admin\Dictionaries;

use App\Models\DictionaryImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DictionaryFileImportRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in([
                DictionaryImport::TYPE_KASTA_CATEGORIES,
                DictionaryImport::TYPE_KASTA_ATTRIBUTES,
                DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES,
                DictionaryImport::TYPE_SIZE_GRIDS,
            ])],
            'file' => ['nullable', 'file'],
            'path' => ['nullable', 'string', 'max:2048'],
            'format' => ['nullable', 'string', Rule::in(['json', 'csv'])],
            'dry_run' => ['nullable', 'boolean'],
            'deactivate_missing' => ['nullable', 'boolean'],
        ];
    }
}
