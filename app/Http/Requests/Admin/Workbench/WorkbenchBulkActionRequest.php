<?php

namespace App\Http\Requests\Admin\Workbench;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkbenchBulkActionRequest extends FormRequest
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
            'operation' => ['required', Rule::in(['exclude_items', 'revalidate_items', 'rebuild_candidate'])],
            'feed_item_ids' => ['nullable', 'array'],
            'feed_item_ids.*' => [
                'integer',
                Rule::exists('feed_items', 'id')->where(fn ($query) => $query->where('feed_profile_id', $feedProfile->id)),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'confirm' => [$this->routeIs('admin.feed-profiles.workbench.bulk-execute') ? 'accepted' : 'nullable'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $operation = $this->input('operation');
            $feedItemIds = $this->input('feed_item_ids', []);

            if (in_array($operation, ['exclude_items', 'revalidate_items'], true) && count($feedItemIds) === 0) {
                $validator->errors()->add('feed_item_ids', 'Select at least one feed item for this bulk action.');
            }

            if (in_array($operation, ['exclude_items', 'rebuild_candidate'], true) && blank($this->input('reason'))) {
                $validator->errors()->add('reason', 'Reason is required for this operation.');
            }
        });
    }
}
