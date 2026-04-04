<?php

namespace App\Http\Requests\Admin\Access;

use App\Models\ShopMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopMembershipStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ShopMembership::statuses())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
