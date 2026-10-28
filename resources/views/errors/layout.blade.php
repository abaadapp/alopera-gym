@php
    // Error pages must render even when the session, tenant or assets are unavailable.
    $home = '/';
    try {
        $user = auth()->user();
        if ($user && method_exists($user, 'homeRoute')) {
            $home = route($user->homeRoute());
        }
    } catch (\Throwable) {
    }
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title')</title>
    <style>
        :root { --brand: #9d174d; --ink: #2a1f2d; --muted: #7a6e7e; --line: #ece4ee; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: #fbf8fb; color: var(--ink);
               font-family: "IBM Plex Sans Arabic", "Segoe UI", Tahoma, "Geeza Pro", system-ui, sans-serif; }
        main { width: 100%; max-width: 440px; text-align: center; background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 40px 28px; }
        .code { font-size: 56px; font-weight: 700; color: var(--brand); letter-spacing: 2px; margin: 0; }
        h1 { font-size: 20px; margin: 8px 0 10px; }
        p { color: var(--muted); line-height: 1.8; margin: 0 0 24px; font-size: 15px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        a, button { font: inherit; font-size: 14px; border-radius: 999px; padding: 10px 20px; text-decoration: none; cursor: pointer; }
        .primary { background: var(--brand); color: #fff; border: 0; }
        .ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
    </style>
</head>
<body>
<main>
    <p class="code">@yield('code')</p>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <div class="actions">
        <a class="primary" href="{{ $home }}">الصفحة الرئيسية</a>
        <button class="ghost" type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ $home }}')">رجوع</button>
    </div>
</main>
</body>
</html>
