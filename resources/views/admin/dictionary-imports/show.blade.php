@extends('layouts.admin', ['title' => 'Dictionary Import #'.$dictionaryImport->id])

@section('subtitle', 'Detailed result, source metadata and replay options for a single dictionary import.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.dictionary-imports.index') }}">Back to history</a>
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Type</strong><div>{{ $dictionaryImport->type }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $dictionaryImport->status }}{{ $dictionaryImport->dry_run ? ' (dry-run)' : '' }}</div></div>
            <div class="detail-row"><strong>Source format</strong><div>{{ $dictionaryImport->source_format }}</div></div>
            <div class="detail-row"><strong>Source file</strong><div>{{ $dictionaryImport->original_filename ?: basename($dictionaryImport->source_path) }}</div></div>
            <div class="detail-row"><strong>Stored path</strong><div>{{ $dictionaryImport->source_path }}</div></div>
            <div class="detail-row"><strong>Checksum</strong><div><code>{{ $dictionaryImport->checksum }}</code></div></div>
            <div class="detail-row"><strong>Rows total</strong><div>{{ $dictionaryImport->rows_total }}</div></div>
            <div class="detail-row"><strong>Created</strong><div>{{ $dictionaryImport->created_count }}</div></div>
            <div class="detail-row"><strong>Updated</strong><div>{{ $dictionaryImport->updated_count }}</div></div>
            <div class="detail-row"><strong>Skipped</strong><div>{{ $dictionaryImport->skipped_count }}</div></div>
            <div class="detail-row"><strong>Deactivated</strong><div>{{ $dictionaryImport->deactivated_count }}</div></div>
            <div class="detail-row"><strong>Initiated by</strong><div>{{ $dictionaryImport->initiatedBy?->email ?: 'system' }}</div></div>
            <div class="detail-row"><strong>Started at</strong><div>{{ optional($dictionaryImport->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Finished at</strong><div>{{ optional($dictionaryImport->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Error</strong><div>{{ $dictionaryImport->error_summary ?: 'n/a' }}</div></div>
        </div>
    </section>

    <section class="panel">
        <h2>Replay</h2>
        <form method="POST" action="{{ route('admin.dictionary-imports.reimport') }}" class="toolbar">
            @csrf
            <input type="hidden" name="type" value="{{ $dictionaryImport->type }}">
            <label class="check"><input type="checkbox" name="dry_run" value="1"> Dry-run</label>
            <label class="check"><input type="checkbox" name="deactivate_missing" value="1"> Deactivate missing</label>
            <button class="button" type="submit">Reimport latest for this type</button>
        </form>
    </section>

    <section class="panel">
        <h2>Metadata</h2>
        <pre>{{ json_encode($dictionaryImport->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </section>
@endsection
