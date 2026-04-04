<?php

namespace App\Http\Requests\Admin\Access;

use Illuminate\Foundation\Http\FormRequest;

class AdminInviteRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:40'],
            'shop_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string'],
        ];
    }
}
