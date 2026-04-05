@if(session('status'))
    <div class="panel" style="background: var(--success-soft); border-color: #bde3cb; color: var(--success);" data-testid="flash-status">
        {{ session('status') }}
    </div>
@endif

@if(session('error'))
    <div class="panel" style="background: var(--danger-soft); border-color: #f2b3b3; color: var(--danger);" data-testid="flash-error">
        {{ session('error') }}
    </div>
@endif

@if(session('admin_governance_feedback'))
    @php($feedback = session('admin_governance_feedback'))
    <div class="panel" style="background: var(--warning-soft); border-color: #edd595; color: var(--warning);" data-testid="flash-governance">
        <strong>{{ $feedback['title'] ?? 'Operator action required' }}</strong><br>
        <span>{{ $feedback['message'] ?? '' }}</span>
        @if($feedback['approval_id'] ?? null)
            <div class="muted" style="margin-top: 6px;">Approval request #{{ $feedback['approval_id'] }}</div>
        @endif
        @if($feedback['action_url'] ?? null)
            <div class="toolbar" style="margin-top: 12px; margin-bottom: 0;">
                <a class="button warning" href="{{ $feedback['action_url'] }}">{{ $feedback['action_label'] ?? 'Open next step' }}</a>
            </div>
        @endif
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
