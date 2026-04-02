<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Admin Login' }}</title>
    <style>
        body { margin: 0; font-family: "Segoe UI", sans-serif; background: linear-gradient(135deg, #f6f1e7 0%, #e6eef3 100%); color: #1d2939; }
        .shell { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: min(420px, 100%); background: rgba(255,255,255,0.92); border: 1px solid #d7dde4; border-radius: 18px; box-shadow: 0 24px 60px rgba(27, 39, 51, 0.10); padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0 0 18px; color: #526071; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid #c6ced6; box-sizing: border-box; margin-bottom: 16px; }
        .button { width: 100%; background: #0d5c63; color: #fff; border: 0; border-radius: 10px; padding: 12px 14px; font-weight: 700; cursor: pointer; }
        .message { background: #fff4d6; border: 1px solid #edd595; color: #73510d; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .error { color: #a61b1b; font-size: 13px; margin-top: -10px; margin-bottom: 12px; }
        .check { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; color: #526071; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        @yield('content')
    </div>
</div>
</body>
</html>
