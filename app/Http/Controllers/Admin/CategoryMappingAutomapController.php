<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Mappings\RunCategoryAutomapAction;
use App\Http\Requests\Admin\Mappings\CategoryAutomapRequest;
use App\Models\FeedProfile;
use Illuminate\Http\RedirectResponse;

class CategoryMappingAutomapController extends AdminController
{
    public function store(CategoryAutomapRequest $request, FeedProfile $feedProfile, RunCategoryAutomapAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        $summary = $action->handle($feedProfile, $request->validated('source_category_ids') ?? []);

        return back()->with('status', sprintf(
            'Automap completed: %d created, %d updated, %d skipped.',
            $summary['created'],
            $summary['updated'],
            $summary['skipped']
        ));
    }
}
