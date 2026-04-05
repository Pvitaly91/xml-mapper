<?php

namespace App\Http\Requests\Admin\FeedItems;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedItemEnrichmentApplyRequest extends FormRequest
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
            'feed_item_ids' => ['required', 'array', 'min:1'],
            'feed_item_ids.*' => [
                'integer',
                Rule::exists('feed_items', 'id')->where(fn ($query) => $query->where('feed_profile_id', $feedProfile->id)),
            ],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
