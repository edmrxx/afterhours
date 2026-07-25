<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'After Hours') }}</title>

    <link rel="icon" href="/images/brand/logo-icon-192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/images/brand/logo-icon-512.png" sizes="512x512" type="image/png">
    <link rel="apple-touch-icon" href="/images/brand/logo-icon-512.png">
    <meta name="theme-color" content="#781714">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://fonts.bunny.net/css?family=archivo:700,800,900&display=swap" rel="stylesheet">

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans antialiased bg-bone-100 text-ink-900">
    @inertia
</body>
</html>
