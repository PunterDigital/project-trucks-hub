<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Instrument Sans from our own origin (#5733): no third-party DNS + TLS before first paint, and no
             download during the Forge build (Panel #3197). Files in public/fonts/instrument-sans (OFL). --}}
        <link rel="preload" href="/fonts/instrument-sans/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
        <style>
            @foreach ([400, 500, 600] as $weight)
                @foreach (['latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD', 'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF'] as $subset => $range)
            @font-face {
                font-family: 'Instrument Sans';
                font-style: normal;
                font-weight: {{ $weight }};
                font-display: swap;
                src: url(/fonts/instrument-sans/instrument-sans-{{ $subset }}-{{ $weight }}-normal.woff2) format('woff2');
                unicode-range: {{ $range }};
            }
                @endforeach
            @endforeach
        </style>

        {{-- Link previews (#4652): printed by Blade, so crawlers get them without SSR. Built as a guest sees the item. --}}
        <meta property="og:site_name" content="{{ config('app.name') }}">
        {{-- The blog's RSS feed (#5150), for feed readers on any page. --}}
        <link rel="alternate" type="application/rss+xml" title="{{ config('app.name') }} blog" href="{{ route('blog.feed') }}">
        {{-- A closure, so Inertia's JSON reloads (which never render this view) skip its queries. --}}
        @php($preview = isset($preview) ? value($preview) : null)
        {{-- Search tags (#5149): title, description, canonical and robots per page (App\Support\Seo). --}}
        @php($seo = \App\Support\Seo::forRequest(request(), $preview))
        @if ($seo['index'])
            <link rel="canonical" href="{{ $seo['canonical'] }}">
            @unless ($preview)
                <meta name="description" content="{{ $seo['description'] }}">
                <meta property="og:type" content="website">
                <meta property="og:title" content="{{ $seo['title'] }}">
                <meta property="og:description" content="{{ $seo['description'] }}">
                <meta property="og:url" content="{{ $seo['canonical'] }}">
                <meta name="twitter:card" content="summary">
            @endunless
            @foreach ($seo['json_ld'] as $data)
                <script type="application/ld+json">{!! \App\Support\Seo::encode($data) !!}</script>
            @endforeach
        @else
            <meta name="robots" content="noindex">
        @endif
        @isset($preview)
            <meta property="og:type" content="website">
            <meta property="og:title" content="{{ $preview['title'] }}">
            <meta property="og:description" content="{{ $preview['description'] }}">
            <meta property="og:url" content="{{ $preview['url'] }}">
            <meta property="og:image" content="{{ $preview['image'] }}">
            <meta property="og:image:type" content="image/png">
            <meta property="og:image:width" content="{{ \App\Support\LinkPreview::WIDTH }}">
            <meta property="og:image:height" content="{{ \App\Support\LinkPreview::HEIGHT }}">
            <meta property="og:image:alt" content="{{ $preview['image_alt'] }}">
            <meta name="description" content="{{ $preview['description'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $preview['title'] }}">
            <meta name="twitter:description" content="{{ $preview['description'] }}">
            <meta name="twitter:image" content="{{ $preview['image'] }}">
            <meta name="theme-color" content="#f59e0b">
        @endisset

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $seo['title'] !== null ? $seo['title'].' - '.config('app.name') : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
