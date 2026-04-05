<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Admin Login' }}</title>
    <style>
        body { margin: 0; font-family: "Segoe UI", sans-serif; background: linear-gradient(135deg, #f6f1e7 0%, #e6eef3 100%); color: #1d2939; }
        .shell { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: min(560px, 100%); background: rgba(255,255,255,0.94); border: 1px solid #d7dde4; border-radius: 18px; box-shadow: 0 24px 60px rgba(27, 39, 51, 0.10); padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0 0 18px; color: #526071; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type="email"], input[type="password"], input[type="text"] { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid #c6ced6; box-sizing: border-box; margin-bottom: 16px; }
        .button { width: 100%; background: #0d5c63; color: #fff; border: 0; border-radius: 10px; padding: 12px 14px; font-weight: 700; cursor: pointer; }
        .button.secondary { background: #e1f0f2; color: #0d5c63; }
        .message { background: #fff4d6; border: 1px solid #edd595; color: #73510d; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .error { color: #a61b1b; font-size: 13px; margin-top: -10px; margin-bottom: 12px; }
        .check { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; color: #526071; }
        code, pre { display: block; white-space: pre-wrap; background: rgba(13, 92, 99, 0.07); border-radius: 10px; padding: 12px; font-family: Consolas, "Courier New", monospace; font-size: 13px; color: #173b43; margin-bottom: 16px; }
        ul { padding-left: 18px; color: #526071; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid #d7dde4; background: #eef5f6; color: #0d5c63; }
        .badge.warn { background: #fff4d6; color: #8e5f00; border-color: #edd595; }
        .badge.ok { background: #e4f7ea; color: #0b6b3b; border-color: #bde3cb; }
        .eyebrow { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        .callout { background: #f6fafb; border: 1px solid #d7dde4; border-radius: 12px; padding: 14px; margin-bottom: 16px; }
        .callout strong { display: block; margin-bottom: 6px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .actions .button { width: auto; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        @isset($appEnvironment)
            <div class="eyebrow">
                <span class="badge {{ $appEnvironment['is_production'] ? 'ok' : ($appEnvironment['is_staging'] ? 'warn' : '') }}">{{ $appEnvironment['label'] }}</span>
                @if($appEnvironment['warnings'][0] ?? null)
                    <span style="font-size: 12px; color: #526071; text-align: right;">{{ $appEnvironment['warnings'][0] }}</span>
                @endif
            </div>
        @endisset
        @yield('content')
    </div>
</div>
</body>
</html>
