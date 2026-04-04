<?php

namespace App\Http\Requests\Admin\SourceConnections;

use App\Models\SourceConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SourceConnectionRequest extends FormRequest
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
        $connection = $this->route('source_connection');
        $shopId = $this->currentShopId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('source_connections', 'code')
                    ->where(fn ($query) => $query->where('shop_id', $shopId))
                    ->ignore($connection?->id),
            ],
            'driver' => ['required', Rule::in(SourceConnection::supportedDrivers())],
            'status' => ['required', Rule::in([SourceConnection::STATUS_ACTIVE, SourceConnection::STATUS_PAUSED])],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'api_base_url' => ['nullable', 'string', 'max:2048'],
            'api_token' => ['nullable', 'string', 'max:4096'],
            'api_version' => ['nullable', 'string', 'max:32'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'credentials_json' => ['nullable', 'json'],
            'options_json' => ['nullable', 'json'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source_url' => $this->normalizeNullableString($this->input('source_url')),
            'api_base_url' => $this->normalizeNullableString($this->input('api_base_url')),
            'api_token' => $this->normalizeNullableString($this->input('api_token')),
            'api_version' => $this->normalizeNullableString($this->input('api_version')),
            'credentials_json' => $this->normalizeNullableString($this->input('credentials_json')),
            'options_json' => $this->normalizeNullableString($this->input('options_json')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $driver = $this->input('driver');
            $connection = $this->route('source_connection');

            if ($driver === SourceConnection::DRIVER_PROM_YML && blank($this->input('source_url'))) {
                $validator->errors()->add('source_url', 'Source URL is required for Prom YML connections.');
            }

            if ($driver === SourceConnection::DRIVER_PROM_API) {
                if (blank($this->input('api_token')) && blank($connection?->api_token)) {
                    $validator->errors()->add('api_token', 'API token is required for Prom API connections.');
                }

                $baseUrl = $this->input('api_base_url') ?: $connection?->api_base_url ?: SourceConnection::defaultPromApiBaseUrl();

                if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    $validator->errors()->add('api_base_url', 'Prom API base URL must be a valid URL.');
                }
            }
        });
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function currentShopId(): ?int
    {
        return $this->attributes->get('admin_shop')?->id ?: $this->user()?->shop_id;
    }
}
