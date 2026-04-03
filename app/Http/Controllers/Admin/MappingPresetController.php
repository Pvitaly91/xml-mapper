<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MappingPresets\MappingPresetImportRequest;
use App\Models\FeedProfile;
use App\Services\Shops\MappingPresetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MappingPresetController extends AdminController
{
    public function importForm(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.mapping-presets.import', [
            'feedProfile' => $feedProfile,
            'preview' => null,
            'presetJson' => '',
            'collisionStrategy' => 'skip_existing',
        ]);
    }

    public function export(Request $request, FeedProfile $feedProfile, MappingPresetService $service): StreamedResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $payload = json_encode($service->export($feedProfile), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static fn () => print ($payload),
            sprintf('feed-profile-%d-mapping-preset.json', $feedProfile->id),
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function preview(MappingPresetImportRequest $request, FeedProfile $feedProfile, MappingPresetService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);
        $preset = json_decode($request->validated('preset_json'), true, 512, JSON_THROW_ON_ERROR);

        return view('admin.mapping-presets.import', [
            'feedProfile' => $feedProfile,
            'preview' => $service->previewImport($feedProfile, $preset, $request->validated('collision_strategy')),
            'presetJson' => $request->validated('preset_json'),
            'collisionStrategy' => $request->validated('collision_strategy'),
        ]);
    }

    public function store(MappingPresetImportRequest $request, FeedProfile $feedProfile, MappingPresetService $service): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $preset = json_decode($request->validated('preset_json'), true, 512, JSON_THROW_ON_ERROR);
        $summary = $service->import($feedProfile, $preset, $request->validated('collision_strategy'));

        return redirect()
            ->route('admin.feed-profiles.mapping-presets.import', $feedProfile)
            ->with(
                'status',
                sprintf(
                    'Preset imported. Category mappings: %d create / %d update. Attribute mappings: %d create / %d update. Value mappings: %d create / %d update.',
                    $summary['summary']['category_mappings']['create'],
                    $summary['summary']['category_mappings']['update'],
                    $summary['summary']['attribute_mappings']['create'],
                    $summary['summary']['attribute_mappings']['update'],
                    $summary['summary']['value_mappings']['create'],
                    $summary['summary']['value_mappings']['update'],
                )
            );
    }
}
