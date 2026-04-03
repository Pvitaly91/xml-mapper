<?php

namespace App\Http\Requests\Admin\Shops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopProfileRequest extends FormRequest
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
        $shopId = $this->user()?->shop_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('shops', 'slug')->ignore($shopId),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $timezone = is_string($value) ? trim($value) : '';

                    if ($timezone === 'Europe/Kiev' || in_array($timezone, timezone_identifiers_list(), true)) {
                        return;
                    }

                    $fail('The selected timezone is invalid.');
                },
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
