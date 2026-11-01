<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('layouts._theme-script')

        <title>{{ config('app.name', 'Helm') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="h-full font-sans antialiased">
        <div class="flex min-h-full flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
            <div class="mb-8 text-center">
                <span class="font-display text-2xl font-bold tracking-tight text-slate-900">
                    {{ config('app.name', 'Helm') }}
                </span>
            </div>

            <div class="w-full max-w-md rounded-2xl bg-white px-8 py-10 shadow-card ring-1 ring-slate-900/5">
                {{ $slot }}
            </div>
        </div>
        @livewireScripts
    </body>
</html>
