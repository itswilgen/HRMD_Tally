<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Two families. Source Sans 3 runs the whole interface: it was drawn
             for screens, stays legible at 12px in a dense grid, and has true
             tabular figures for columns of days. Poppins sets titles only —
             page titles, the product name and the sign-in heading. --}}
        {{-- The City of Tanjay seal in the browser tab and on a phone's home screen. --}}
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" type="image/png" href="/images/brand/favicon-32.png" sizes="32x32">
        <link rel="apple-touch-icon" href="/images/brand/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700|poppins:600,700&display=swap" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
