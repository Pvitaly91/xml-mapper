<?php

namespace App\Http\Requests\Admin\FeedItems;

use Illuminate\Foundation\Http\FormRequest;

class FeedItemContentOverrideRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'article' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:255'],
            'size_grid_code' => ['nullable', 'string', 'max:120'],
            'images' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $fields = collect($this->only([
                'title',
                'description',
                'vendor',
                'article',
                'color',
                'size',
                'size_grid_code',
                'images',
            ]))->filter(fn ($value) => is_string($value) && trim($value) !== '');

            if ($fields->isNotEmpty() && blank($this->input('reason'))) {
                $validator->errors()->add('reason', 'Reason is required when saving a content override.');
            }
        });
    }
}
