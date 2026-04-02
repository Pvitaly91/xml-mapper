@if(session('status'))
    <div class="panel" style="background: var(--success-soft); border-color: #bde3cb; color: var(--success);">
        {{ session('status') }}
    </div>
@endif

@if(session('error'))
    <div class="panel" style="background: var(--danger-soft); border-color: #f2b3b3; color: var(--danger);">
        {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="panel" style="background: var(--danger-soft); border-color: #f2b3b3;">
        <ul class="error-list">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
