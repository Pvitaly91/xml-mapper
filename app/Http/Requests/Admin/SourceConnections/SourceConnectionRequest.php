<?php

namespace App\Http\Requests\Admin\SourceConnections;

use App\Models\SourceConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('source_connections', 'code')
                    ->where(fn ($query) => $query->where('shop_id', $this->user()->shop_id))
                    ->ignore($connection?->id),
            ],
            'driver' => ['required', Rule::in([SourceConnection::DRIVER_PROM_YML])],
            'status' => ['required', Rule::in([SourceConnection::STATUS_ACTIVE, SourceConnection::STATUS_PAUSED])],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'credentials_json' => ['nullable', 'json'],
            'options_json' => ['nullable', 'json'],
        ];
    }
}
