@php
    $seo = $page['props']['seo'] ?? null;
    $tenant = $page['props']['tenant'] ?? null;
    $brand = $tenant['primary_color'] ?? '#9d174d';
    $brand2 = $tenant['secondary_color'] ?? '#f9a8d4';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $brand }}">
    {{-- Rendered on the server so search engines and link previews see them without running JavaScript --}}
    @if ($seo)
        <title inertia>{{ $seo['title'] ?? config('app.name') }}</title>
        <meta name="description" content="{{ $seo['description'] ?? '' }}">
        <link rel="canonical" href="{{ $seo['canonical'] ?? url()->current() }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $seo['title'] ?? '' }}">
        <meta property="og:description" content="{{ $seo['description'] ?? '' }}">
        <meta property="og:url" content="{{ $seo['canonical'] ?? url()->current() }}">
        @if (! empty($seo['image']))<meta property="og:image" content="{{ $seo['image'] }}">@endif
        <meta property="og:locale" content="ar_OM">
        <meta name="twitter:card" content="summary_large_image">
        @if (! empty($seo['jsonld']))<script type="application/ld+json">{!! json_encode($seo['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>@endif
    @else
        <title inertia>{{ config('app.name') }}</title>
        <meta name="robots" content="noindex">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&family=Readex+Pro:wght@500;600;700&display=swap" rel="stylesheet">
    <style>:root{--brand: {{ $brand }}; --brand-soft: {{ $brand2 }};}</style>
    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <x-inertia::head />
</head>
<body class="antialiased">
    <x-inertia::app />
</body>
</html>
