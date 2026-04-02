<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Mappings\ApplyAttributeMappingSuggestionsAction;
use App\Http\Requests\Admin\Mappings\AttributeSuggestionRequest;
use App\Models\FeedProfile;
use Illuminate\Http\RedirectResponse;

class AttributeMappingSuggestionController extends AdminController
{
    public function store(AttributeSuggestionRequest $request, FeedProfile $feedProfile, ApplyAttributeMappingSuggestionsAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        $summary = $action->handle(
            $feedProfile,
            (int) $request->validated('source_category_id'),
            $request->validated('source_attribute_ids') ?? []
        );

        return back()->with('status', sprintf(
            'Attribute suggestions applied: %d created, %d skipped.',
            $summary['created'],
            $summary['skipped']
        ));
    }
}
