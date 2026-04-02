<?php

namespace App\Http\Requests\Admin\FeedProfiles;

use App\Models\FeedProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedProfileRequest extends FormRequest
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
        $feedProfile = $this->route('feed_profile');

        return [
            'source_connection_id' => [
                'required',
                'integer',
                Rule::exists('source_connections', 'id')->where(fn ($query) => $query->where('shop_id', $this->user()->shop_id)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('feed_profiles', 'code')
                    ->where(fn ($query) => $query->where('shop_id', $this->user()->shop_id))
                    ->ignore($feedProfile?->id),
            ],
            'status' => ['required', Rule::in([FeedProfile::STATUS_DRAFT, FeedProfile::STATUS_ACTIVE, FeedProfile::STATUS_INACTIVE])],
            'currency' => ['required', 'string', 'size:3'],
            'language' => ['required', 'string', 'max:10'],
            'include_unavailable' => ['nullable', 'boolean'],
            'auto_sync' => ['nullable', 'boolean'],
            'auto_build' => ['nullable', 'boolean'],
            'build_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'settings_json' => ['nullable', 'json'],
        ];
    }
}
