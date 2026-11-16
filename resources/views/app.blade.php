<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locale::dir(app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ __('ui.app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700|instrument-sans:400,500,600" rel="stylesheet" />

        {{-- the one inline script on the page; the nonce lets it past the Content-Security-Policy --}}
        @routes(auth()->check() ? null : 'guest', nonce: Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
