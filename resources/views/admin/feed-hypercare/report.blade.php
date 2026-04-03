@extends('layouts.admin', ['title' => $feedProfile->name.' '.$reportTitle])

@section('subtitle', 'Printable hypercare report output for operator review and handoff.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">Back to war room</a>
            <a class="button secondary" href="{{ request()->fullUrlWithQuery(['download' => 1]) }}">Download markdown</a>
        </div>
        <pre>{{ $report['content'] }}</pre>
        <p class="muted">Saved at: {{ $report['absolute_path'] }}</p>
    </section>
@endsection
