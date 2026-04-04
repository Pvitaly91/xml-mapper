<?php

namespace App\Http\Requests\Admin\Launch;

use Illuminate\Foundation\Http\FormRequest;

class MerchantLaunchStartRequest extends FormRequest
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
            'feed_profile_id' => ['required', 'integer'],
            'pilot_run_id' => ['nullable', 'integer'],
            'promotion_run_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
