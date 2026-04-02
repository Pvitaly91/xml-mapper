<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Dictionaries\ImportKastaDictionariesAction;
use App\Http\Requests\Admin\Dictionaries\DictionaryImportRequest;
use App\Models\DictionaryImport;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\SizeGrid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DictionaryController extends AdminController
{
    public function index(Request $request): View
    {
        $shop = $this->adminShop($request);

        return view('admin.dictionaries.index', [
            'shop' => $shop,
            'counts' => [
                'categories' => KastaCategory::query()->count(),
                'attributes' => KastaAttribute::query()->count(),
                'attribute_values' => KastaAttributeValue::query()->count(),
                'size_grids' => SizeGrid::query()->where(fn ($query) => $query->whereNull('shop_id')->orWhere('shop_id', $shop->id))->count(),
            ],
            'recentCategories' => KastaCategory::query()->orderByDesc('id')->limit(10)->get(),
            'recentImports' => DictionaryImport::query()->latest('id')->limit(10)->get(),
        ]);
    }

    public function categories(Request $request): View
    {
        $categories = KastaCategory::query()
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', (bool) $request->boolean('is_active')))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('full_path', 'like', '%'.$search.'%')
                        ->orWhere('external_id', 'like', '%'.$search.'%')
                        ->orWhere('rz_id', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('full_path')
            ->paginate(20)
            ->withQueryString();

        return view('admin.dictionaries.categories', [
            'categories' => $categories,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    public function attributes(Request $request): View
    {
        $attributes = KastaAttribute::query()
            ->with('kastaCategory')
            ->when($request->integer('kasta_category_id'), fn ($query, $categoryId) => $query->where('kasta_category_id', $categoryId))
            ->when($request->filled('required_only'), fn ($query) => $query->where('is_required', true))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('external_id', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('kasta_category_id')
            ->orderBy('sort_order')
            ->paginate(20)
            ->withQueryString();

        return view('admin.dictionaries.attributes', [
            'attributes' => $attributes,
            'categories' => KastaCategory::query()->orderBy('full_path')->get(),
            'filters' => $request->only(['search', 'kasta_category_id', 'required_only']),
        ]);
    }

    public function values(Request $request): View
    {
        $values = KastaAttributeValue::query()
            ->with('kastaAttribute.kastaCategory')
            ->when($request->integer('kasta_category_id'), fn ($query, $categoryId) => $query->whereHas('kastaAttribute', fn ($innerQuery) => $innerQuery->where('kasta_category_id', $categoryId)))
            ->when($request->integer('kasta_attribute_id'), fn ($query, $attributeId) => $query->where('kasta_attribute_id', $attributeId))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('value', 'like', '%'.$search.'%')
                        ->orWhere('external_id', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('kasta_attribute_id')
            ->orderBy('sort_order')
            ->paginate(20)
            ->withQueryString();

        return view('admin.dictionaries.values', [
            'values' => $values,
            'categories' => KastaCategory::query()->orderBy('full_path')->get(),
            'attributes' => KastaAttribute::query()->orderBy('name')->get(),
            'filters' => $request->only(['search', 'kasta_category_id', 'kasta_attribute_id']),
        ]);
    }

    public function sizeGrids(Request $request): View
    {
        $shop = $this->adminShop($request);

        $sizeGrids = SizeGrid::query()
            ->where(fn ($query) => $query->whereNull('shop_id')->orWhere('shop_id', $shop->id))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', (bool) $request->boolean('is_active')))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('shop_id')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.dictionaries.size-grids', [
            'sizeGrids' => $sizeGrids,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    public function import(DictionaryImportRequest $request, ImportKastaDictionariesAction $action): RedirectResponse
    {
        $summary = $action->handle($request->validated('path'), $request->user()?->id);

        return back()->with('status', sprintf(
            'Dictionaries imported: %d categories, %d attributes, %d values, %d size grids.',
            $summary['categories'],
            $summary['attributes'],
            $summary['attribute_values'],
            $summary['size_grids']
        ));
    }
}
