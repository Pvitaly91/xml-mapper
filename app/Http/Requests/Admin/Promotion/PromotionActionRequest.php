<?php

namespace App\Http\Requests\Admin\Promotion;

use App\Models\PromotionRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionActionRequest extends FormRequest
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
            'source_snapshot_id' => ['required', 'integer', 'exists:promotion_snapshots,id'],
            'strategy' => ['nullable', Rule::in(PromotionRun::strategies())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
