<?php

namespace App\Http\Requests\Admin\Access;

use Illuminate\Foundation\Http\FormRequest;

class UserLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'shop_id' => ['nullable', 'integer'],
        ];
    }
}
