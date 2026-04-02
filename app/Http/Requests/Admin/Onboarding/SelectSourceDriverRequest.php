<?php

namespace App\Http\Requests\Admin\Onboarding;

use App\Models\SourceConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectSourceDriverRequest extends FormRequest
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
            'driver' => ['required', Rule::in(SourceConnection::supportedDrivers())],
        ];
    }
}
