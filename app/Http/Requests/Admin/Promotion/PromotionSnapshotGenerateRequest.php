<?php

namespace App\Http\Requests\Admin\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class PromotionSnapshotGenerateRequest extends FormRequest
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
            'env' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
