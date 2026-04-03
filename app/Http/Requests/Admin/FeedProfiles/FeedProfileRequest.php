<?php

namespace App\Http\Requests\Admin\FeedProfiles;

use App\Models\FeedProfile;
use App\Models\FeedGenerationSignoff;
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
            'publish_guard_enabled' => ['nullable', 'boolean'],
            'minimum_ready_items' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'maximum_invalid_ratio' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'block_publish_on_critical_conformance' => ['nullable', 'boolean'],
            'minimum_pictures' => ['nullable', 'integer', 'min:1', 'max:50'],
            'signoff_required' => ['nullable', 'boolean'],
            'required_signoff_status' => ['nullable', Rule::in(FeedGenerationSignoff::statuses())],
            'publish_window_enabled' => ['nullable', 'boolean'],
            'publish_window_days' => ['nullable', 'array'],
            'publish_window_days.*' => ['string', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'publish_window_start' => ['nullable', 'date_format:H:i'],
            'publish_window_end' => ['nullable', 'date_format:H:i'],
            'publish_window_timezone' => ['nullable', 'timezone'],
            'freeze_mode' => ['nullable', 'boolean'],
            'settings_json' => ['nullable', 'json'],
        ];
    }
}
