<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Mappings\ApproveValueMappingSuggestionsAction;
use App\Actions\Admin\Mappings\PreviewValueMappingSuggestionsAction;
use App\Actions\Admin\Mappings\UpsertValueMappingAction;
use App\Http\Requests\Admin\Mappings\ValueMappingRequest;
use App\Http\Requests\Admin\Mappings\ValueSuggestionRequest;
use App\Models\AttributeMapping;
use App\Models\FeedProfile;
use App\Models\ValueMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ValueMappingController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile, PreviewValueMappingSuggestionsAction $previewAction): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $attributeMappings = AttributeMapping::query()
            ->with(['sourceAttribute', 'kastaAttribute'])
            ->where('feed_profile_id', $feedProfile->id)
            ->orderBy('source_category_id')
            ->orderBy('id')
            ->get();

        $selectedAttributeMapping = $request->integer('attribute_mapping_id')
            ? $attributeMappings->firstWhere('id', $request->integer('attribute_mapping_id'))
            : $attributeMappings->first();

        $valueMappings = ValueMapping::query()
            ->with(['sourceAttributeValue', 'kastaAttributeValue'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($selectedAttributeMapping !== null, fn ($query) => $query->where('attribute_mapping_id', $selectedAttributeMapping->id))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        if ($selectedAttributeMapping !== null) {
            $selectedAttributeMapping->loadMissing(['sourceAttribute.values', 'kastaAttribute.values']);
        }

        return view('admin.value-mappings.index', [
            'feedProfile' => $feedProfile,
            'attributeMappings' => $attributeMappings,
            'selectedAttributeMapping' => $selectedAttributeMapping,
            'valueMappings' => $valueMappings,
            'sourceValues' => $selectedAttributeMapping?->sourceAttribute?->values ?? collect(),
            'kastaValues' => $selectedAttributeMapping?->kastaAttribute?->values ?? collect(),
            'selectedValueMapping' => $request->integer('edit')
                ? ValueMapping::query()->with(['sourceAttributeValue', 'kastaAttributeValue'])->where('feed_profile_id', $feedProfile->id)->find($request->integer('edit'))
                : null,
            'suggestions' => $selectedAttributeMapping ? $previewAction->handle($selectedAttributeMapping) : [],
        ]);
    }

    public function store(ValueMappingRequest $request, FeedProfile $feedProfile, AttributeMapping $attributeMapping, UpsertValueMappingAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);

        $action->handle($attributeMapping, $request->validated());

        return back()->with('status', 'Value mapping created.');
    }

    public function update(
        ValueMappingRequest $request,
        FeedProfile $feedProfile,
        AttributeMapping $attributeMapping,
        ValueMapping $valueMapping,
        UpsertValueMappingAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);
        abort_unless($valueMapping->attribute_mapping_id === $attributeMapping->id, 404);

        $action->handle($attributeMapping, $request->validated(), $valueMapping);

        return redirect()
            ->route('admin.feed-profiles.value-mappings.index', ['feed_profile' => $feedProfile, 'attribute_mapping_id' => $attributeMapping->id])
            ->with('status', 'Value mapping updated.');
    }

    public function destroy(Request $request, FeedProfile $feedProfile, AttributeMapping $attributeMapping, ValueMapping $valueMapping): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);
        abort_unless($valueMapping->attribute_mapping_id === $attributeMapping->id, 404);
        $valueMapping->delete();

        return back()->with('status', 'Value mapping deleted.');
    }

    public function approveSuggestions(
        ValueSuggestionRequest $request,
        FeedProfile $feedProfile,
        AttributeMapping $attributeMapping,
        ApproveValueMappingSuggestionsAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($attributeMapping->feed_profile_id === $feedProfile->id, 404);

        $summary = $action->handle($attributeMapping, $request->validated('source_attribute_value_ids') ?? []);

        return back()->with('status', sprintf('Approved %d suggested value mappings.', $summary['created']));
    }
}
