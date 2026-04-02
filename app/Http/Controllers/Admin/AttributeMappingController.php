<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Mappings\PreviewAttributeMappingSuggestionsAction;
use App\Actions\Admin\Mappings\UpsertAttributeMappingAction;
use App\Http\Requests\Admin\Mappings\AttributeMappingRequest;
use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\SourceAttribute;
use App\Models\SourceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttributeMappingController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile, PreviewAttributeMappingSuggestionsAction $previewAction): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $sourceCategoryId = $request->integer('source_category_id') ?: null;
        $targetCategoryId = $request->integer('kasta_category_id') ?: CategoryMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $sourceCategoryId)
            ->where('is_active', true)
            ->value('kasta_category_id');

        $mappings = AttributeMapping::query()
            ->with(['sourceAttribute', 'kastaAttribute.kastaCategory'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($sourceCategoryId !== null, fn ($query) => $query->where('source_category_id', $sourceCategoryId))
            ->when($targetCategoryId !== null, fn ($query) => $query->where('kasta_category_id', $targetCategoryId))
            ->orderBy('source_category_id')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $requiredAttributes = $targetCategoryId
            ? KastaAttribute::query()->where('kasta_category_id', $targetCategoryId)->where('is_required', true)->orderBy('sort_order')->get()
            : collect();
        $mappedTargetIds = AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($sourceCategoryId !== null, fn ($query) => $query->where('source_category_id', $sourceCategoryId))
            ->pluck('kasta_attribute_id')
            ->all();

        return view('admin.attribute-mappings.index', [
            'feedProfile' => $feedProfile,
            'mappings' => $mappings,
            'sourceCategories' => SourceCategory::query()->where('source_connection_id', $feedProfile->source_connection_id)->orderBy('full_path')->get(),
            'sourceAttributes' => SourceAttribute::query()->where('source_connection_id', $feedProfile->source_connection_id)->orderBy('name')->get(),
            'kastaAttributes' => $targetCategoryId
                ? KastaAttribute::query()->where('kasta_category_id', $targetCategoryId)->orderBy('sort_order')->get()
                : KastaAttribute::query()->orderBy('name')->limit(100)->get(),
            'targetCategoryId' => $targetCategoryId,
            'selectedMapping' => $request->integer('edit')
                ? AttributeMapping::query()->with(['sourceAttribute', 'kastaAttribute'])->where('feed_profile_id', $feedProfile->id)->find($request->integer('edit'))
                : null,
            'requiredAttributes' => $requiredAttributes,
            'unmappedRequiredAttributes' => $requiredAttributes->reject(fn ($attribute) => in_array($attribute->id, $mappedTargetIds, true))->values(),
            'suggestions' => $previewAction->handle($feedProfile, $sourceCategoryId),
            'filters' => $request->only(['source_category_id', 'kasta_category_id']),
        ]);
    }

    public function store(AttributeMappingRequest $request, FeedProfile $feedProfile, UpsertAttributeMappingAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $action->handle($feedProfile, $request->validated());

        return back()->with('status', 'Attribute mapping created.');
    }

    public function update(AttributeMappingRequest $request, FeedProfile $feedProfile, AttributeMapping $attributeMapping, UpsertAttributeMappingAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);
        $action->handle($feedProfile, $request->validated(), $attributeMapping);

        return redirect()
            ->route('admin.feed-profiles.attribute-mappings.index', [
                'feed_profile' => $feedProfile,
                'source_category_id' => $request->validated('source_category_id'),
                'kasta_category_id' => $request->validated('kasta_category_id'),
            ])
            ->with('status', 'Attribute mapping updated.');
    }

    public function destroy(Request $request, FeedProfile $feedProfile, AttributeMapping $attributeMapping): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);
        $attributeMapping->delete();

        return back()->with('status', 'Attribute mapping deleted.');
    }
}
