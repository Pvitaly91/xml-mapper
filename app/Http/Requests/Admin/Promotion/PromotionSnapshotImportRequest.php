<?php

namespace App\Http\Requests\Admin\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class PromotionSnapshotImportRequest extends FormRequest
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
            'snapshot_file' => ['required', 'file', 'max:5120'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
