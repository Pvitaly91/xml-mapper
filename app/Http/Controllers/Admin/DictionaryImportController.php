<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Dictionaries\ReimportLatestDictionaryAction;
use App\Actions\Admin\Dictionaries\RunDictionaryImportAction;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Http\Requests\Admin\Dictionaries\DictionaryFileImportRequest;
use App\Http\Requests\Admin\Dictionaries\DictionaryReimportRequest;
use App\Models\DictionaryImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DictionaryImportController extends AdminController
{
    public function index(Request $request): View
    {
        $imports = DictionaryImport::query()
            ->with('initiatedBy')
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('type', $type))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->date('date_from'), fn ($query, $date) => $query->whereDate('started_at', '>=', $date))
            ->when($request->date('date_to'), fn ($query, $date) => $query->whereDate('started_at', '<=', $date))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.dictionary-imports.index', [
            'imports' => $imports,
            'types' => [
                DictionaryImport::TYPE_KASTA_CATEGORIES,
                DictionaryImport::TYPE_KASTA_ATTRIBUTES,
                DictionaryImport::TYPE_KASTA_ATTRIBUTE_VALUES,
                DictionaryImport::TYPE_SIZE_GRIDS,
            ],
            'statuses' => [
                DictionaryImport::STATUS_RUNNING,
                DictionaryImport::STATUS_COMPLETED,
                DictionaryImport::STATUS_SKIPPED,
                DictionaryImport::STATUS_FAILED,
            ],
            'filters' => $request->only(['type', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(DictionaryImport $dictionaryImport): View
    {
        return view('admin.dictionary-imports.show', [
            'dictionaryImport' => $dictionaryImport->load('initiatedBy'),
        ]);
    }

    public function store(DictionaryFileImportRequest $request, RunDictionaryImportAction $action): RedirectResponse
    {
        try {
            $file = $request->file('file');
            $import = $action->handle(new DictionaryImportOptions(
                type: (string) $request->validated('type'),
                filePath: $file?->getRealPath() ?: $request->validated('path'),
                format: $request->validated('format'),
                dryRun: $request->boolean('dry_run'),
                deactivateMissing: $request->boolean('deactivate_missing'),
                initiatedByUserId: $request->user()?->id,
                originalFilename: $file?->getClientOriginalName(),
            ));
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.dictionary-imports.show', $import)
            ->with('status', 'Dictionary import finished with status: '.$import->status.'.');
    }

    public function reimport(DictionaryReimportRequest $request, ReimportLatestDictionaryAction $action): RedirectResponse
    {
        try {
            $import = $action->handle(
                (string) $request->validated('type'),
                $request->boolean('dry_run'),
                $request->boolean('deactivate_missing'),
                $request->user()?->id,
            );
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.dictionary-imports.show', $import)
            ->with('status', 'Dictionary reimport finished with status: '.$import->status.'.');
    }
}
