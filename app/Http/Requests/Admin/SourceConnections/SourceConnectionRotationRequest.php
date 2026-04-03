<?php

namespace App\Http\Requests\Admin\SourceConnections;

use App\Services\Ops\SecretsRotationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceConnectionRotationRequest extends FormRequest
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
            'target' => ['required', Rule::in([
                SecretsRotationService::TARGET_PROM_API_TOKEN,
                SecretsRotationService::TARGET_APP_SECRET,
                SecretsRotationService::TARGET_DEPLOY_CREDENTIALS,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
