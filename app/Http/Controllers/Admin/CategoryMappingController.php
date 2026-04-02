<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Mappings\UpsertCategoryMappingAction;
use App\Http\Requests\Admin\Mappings\CategoryMappingRequest;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryMappingController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $query = SourceCategory::query()
            ->select([
                'source_categories.*',
                'current_mapping.id as mapping_id',
                'current_mapping.kasta_category_id',
                'current_mapping.mapping_strategy',
                'current_mapping.is_active as mapping_is_active',
                'kasta_categories.name as kasta_category_name',
                'kasta_categories.full_path as kasta_category_path',
            ])
            ->leftJoin('category_mappings as current_mapping', function ($join) use ($feedProfile): void {
                $join->on('current_mapping.source_category_id', '=', 'source_categories.id')
                    ->where('current_mapping.feed_profile_id', '=', $feedProfile->id);
            })
            ->leftJoin('kasta_categories', 'kasta_categories.id', '=', 'current_mapping.kasta_category_id')
            ->where('source_categories.source_connection_id', $feedProfile->source_connection_id);

        if ($request->string('mapping_status')->toString() === 'unmapped') {
            $query->whereNull('current_mapping.id');
        }

        if ($request->string('mapping_status')->toString() === 'mapped') {
            $query->whereNotNull('current_mapping.id');
        }

        if ($request->string('strategy')->toString()) {
            $query->where('current_mapping.mapping_strategy', $request->string('strategy')->toString());
        }

        if ($request->filled('is_active')) {
            $query->where('current_mapping.is_active', $request->boolean('is_active'));
        }

        if ($request->string('source_search')->toString()) {
            $search = $request->string('source_search')->toString();
            $query->where(function ($innerQuery) use ($search): void {
                $innerQuery->where('source_categories.name', 'like', '%'.$search.'%')
                    ->orWhere('source_categories.full_path', 'like', '%'.$search.'%')
                    ->orWhere('source_categories.external_id', 'like', '%'.$search.'%');
            });
        }

        if ($request->string('kasta_search')->toString()) {
            $search = $request->string('kasta_search')->toString();
            $query->where(function ($innerQuery) use ($search): void {
                $innerQuery->where('kasta_categories.name', 'like', '%'.$search.'%')
                    ->orWhere('kasta_categories.full_path', 'like', '%'.$search.'%')
                    ->orWhere('kasta_categories.external_id', 'like', '%'.$search.'%')
                    ->orWhere('kasta_categories.rz_id', 'like', '%'.$search.'%');
            });
        }

        return view('admin.category-mappings.index', [
            'feedProfile' => $feedProfile->load('sourceConnection'),
            'rows' => $query->orderBy('source_categories.full_path')->paginate(20)->withQueryString(),
            'kastaCategories' => KastaCategory::query()->where('is_active', true)->orderBy('full_path')->get(),
            'sourceCategories' => SourceCategory::query()->where('source_connection_id', $feedProfile->source_connection_id)->orderBy('full_path')->get(),
            'selectedMapping' => $request->integer('edit')
                ? CategoryMapping::query()->with(['sourceCategory', 'kastaCategory'])->where('feed_profile_id', $feedProfile->id)->find($request->integer('edit'))
                : null,
            'filters' => $request->only(['mapping_status', 'strategy', 'is_active', 'source_search', 'kasta_search']),
        ]);
    }

    public function store(CategoryMappingRequest $request, FeedProfile $feedProfile, UpsertCategoryMappingAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $action->handle($feedProfile, $request->validated());

        return back()->with('status', 'Category mapping created.');
    }

    public function update(CategoryMappingRequest $request, FeedProfile $feedProfile, CategoryMapping $categoryMapping, UpsertCategoryMappingAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($categoryMapping->feed_profile_id === $feedProfile->id, 404);

        $action->handle($feedProfile, $request->validated(), $categoryMapping);

        return redirect()
            ->route('admin.feed-profiles.category-mappings.index', $feedProfile)
            ->with('status', 'Category mapping updated.');
    }

    public function destroy(Request $request, FeedProfile $feedProfile, CategoryMapping $categoryMapping): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($categoryMapping->feed_profile_id === $feedProfile->id, 404);
        $categoryMapping->delete();

        return back()->with('status', 'Category mapping deleted.');
    }

    public function deactivate(Request $request, FeedProfile $feedProfile, CategoryMapping $categoryMapping): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($categoryMapping->feed_profile_id === $feedProfile->id, 404);
        $categoryMapping->update(['is_active' => false]);

        return back()->with('status', 'Category mapping deactivated.');
    }
}
