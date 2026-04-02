<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'XML Mapper Admin' }}</title>
    <style>
        :root { --bg: #f4f1ea; --panel: #ffffff; --panel-alt: #f7f9fb; --line: #d8e0e8; --text: #192230; --muted: #5b6777; --accent: #0d5c63; --accent-soft: #e1f0f2; --warning: #8e5f00; --warning-soft: #fff4d6; --danger: #a61b1b; --danger-soft: #ffe7e7; --success: #0b6b3b; --success-soft: #e4f7ea; }
        * { box-sizing: border-box; } body { margin: 0; font-family: "Segoe UI", sans-serif; background: radial-gradient(circle at top right, #dfeef1 0%, transparent 26%), var(--bg); color: var(--text); } a { color: inherit; text-decoration: none; }
        .app { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; } .sidebar { background: #173b43; color: #f8fbfb; padding: 28px 20px; } .brand { font-size: 22px; font-weight: 800; letter-spacing: 0.04em; margin-bottom: 8px; } .brand small { display: block; font-size: 12px; letter-spacing: 0.08em; opacity: 0.78; margin-top: 4px; }
        .nav { margin-top: 28px; display: grid; gap: 8px; } .nav a { display: block; padding: 10px 12px; border-radius: 10px; color: rgba(248, 251, 251, 0.88); } .nav a.active, .nav a:hover { background: rgba(255,255,255,0.10); color: #fff; }
        .sidebar .meta { margin-top: 28px; font-size: 13px; color: rgba(248,251,251,0.72); } .content { padding: 28px; } .topbar { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 22px; }
        .topbar h1 { margin: 0; font-size: 32px; } .topbar p { margin: 4px 0 0; color: var(--muted); } .logout { border: 1px solid rgba(255,255,255,0.15); background: transparent; color: #fff; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 20px; box-shadow: 0 18px 36px rgba(25, 34, 48, 0.05); margin-bottom: 18px; } .panel h2, .panel h3 { margin-top: 0; }
        .grid { display: grid; gap: 18px; } .grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .stat { padding: 16px; border-radius: 14px; background: var(--panel-alt); border: 1px solid var(--line); } .stat strong { display: block; font-size: 28px; margin-top: 10px; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; } .toolbar form { display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .button, button.button { background: var(--accent); color: #fff; border: 0; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; } .button.secondary { background: var(--accent-soft); color: var(--accent); } .button.warning { background: var(--warning-soft); color: var(--warning); } .button.danger { background: var(--danger-soft); color: var(--danger); } .button.link { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        .table-wrap { overflow-x: auto; } table { width: 100%; border-collapse: collapse; } th, td { padding: 11px 10px; border-bottom: 1px solid var(--line); vertical-align: top; text-align: left; } th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--panel-alt); border: 1px solid var(--line); } .badge.ok { background: var(--success-soft); color: var(--success); border-color: #bde3cb; } .badge.warn { background: var(--warning-soft); color: var(--warning); border-color: #edd595; } .badge.err { background: var(--danger-soft); color: var(--danger); border-color: #f2b3b3; }
        .muted { color: var(--muted); } .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; } .field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; } .field input, .field select, .field textarea { width: 100%; border: 1px solid #c7d0d9; border-radius: 10px; padding: 10px 12px; font: inherit; background: #fff; }
        .field textarea { min-height: 112px; } .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; } .form-grid .full { grid-column: 1 / -1; } .checks { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 6px; } .check { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .detail-list { display: grid; gap: 10px; } .detail-row { display: grid; grid-template-columns: 200px 1fr; gap: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--line); } .detail-row:last-child { border-bottom: 0; padding-bottom: 0; } .error-list { margin: 0; padding-left: 18px; color: var(--danger); }
        pre { white-space: pre-wrap; background: var(--panel-alt); border: 1px solid var(--line); border-radius: 12px; padding: 14px; overflow: auto; }
        @media (max-width: 1080px) { .app { grid-template-columns: 1fr; } .content { padding: 18px; } .grid.cols-2, .form-grid { grid-template-columns: 1fr; } .detail-row { grid-template-columns: 1fr; gap: 6px; } }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">XML Mapper<small>Prom -> Kasta admin</small></div>
        <nav class="nav">
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('admin.source-connections.index') }}" class="{{ request()->routeIs('admin.source-connections.*') ? 'active' : '' }}">Source Connections</a>
            <a href="{{ route('admin.feed-profiles.index') }}" class="{{ request()->routeIs('admin.feed-profiles.*') ? 'active' : '' }}">Feed Profiles</a>
            <a href="{{ route('admin.dictionaries.index') }}" class="{{ request()->routeIs('admin.dictionaries.*') ? 'active' : '' }}">Kasta Dictionaries</a>
        </nav>
        <div class="meta">
            @if(auth()->user())
                <div>{{ auth()->user()->name }}</div>
                <div>{{ auth()->user()->email }}</div>
            @endif
            <form method="POST" action="{{ route('admin.logout') }}" style="margin-top: 14px;">
                @csrf
                <button type="submit" class="logout">Logout</button>
            </form>
        </div>
    </aside>
    <main class="content">
        <div class="topbar">
            <div>
                <h1>{{ $title ?? 'Admin' }}</h1>
                @hasSection('subtitle')
                    <p>@yield('subtitle')</p>
                @endif
            </div>
        </div>
        @include('components.admin.flash')
        @yield('content')
    </main>
</div>
</body>
</html>
