<?php

namespace App\Http\Requests\Admin\Access;

use App\Models\ShopMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'shop_id' => ['nullable', 'integer'],
            'role' => ['required', Rule::in(ShopMembership::roles())],
            'status' => ['nullable', Rule::in(ShopMembership::statuses())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
