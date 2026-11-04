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
    <body class="h-full bg-white font-sans antialiased dark:bg-gray-900">
        <div class="grid min-h-full lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
            {{-- Brand panel --}}
            <aside class="relative hidden overflow-hidden bg-[#0b1b3a] text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
                {{-- Helm wheel: the one decorative element --}}
                <svg class="pointer-events-none absolute -bottom-40 -right-40 h-[38rem] w-[38rem] text-brand-400/20" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
                    <circle cx="100" cy="100" r="92" stroke-width="1"/>
                    <circle cx="100" cy="100" r="64" stroke-width="6"/>
                    <circle cx="100" cy="100" r="18" stroke-width="1"/>
                    @for ($i = 0; $i < 8; $i++)
                        <line x1="100" y1="8" x2="100" y2="82" stroke-width="1" transform="rotate({{ $i * 45 }} 100 100)"/>
                        <circle cx="100" cy="4" r="5" stroke-width="1" transform="rotate({{ $i * 45 }} 100 100)"/>
                    @endfor
                </svg>

                <span class="relative font-display text-2xl font-bold tracking-tight">{{ config('app.name', 'Helm') }}</span>

                <div class="relative max-w-md">
                    <h2 class="font-display text-4xl font-semibold leading-[1.1] tracking-tight">
                        Every project, team and deadline in one view.
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-blue-100/70">
                        Plan sprints, track time against budget, and keep everyone on the same board.
                    </p>

                    {{-- Product glimpse --}}
                    <div class="mt-10 rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-sm">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">Sprint progress</span>
                            <span class="text-blue-100/70">12 of 18 tasks done</span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full w-2/3 rounded-full bg-brand-400"></div>
                        </div>
                        <div class="mt-4 flex items-center gap-1.5 text-xs text-blue-100/70">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            On budget · 3 days left
                        </div>
                    </div>
                </div>

                <p class="relative text-xs text-blue-100/50">&copy; {{ date('Y') }} {{ config('app.name', 'Helm') }}</p>
            </aside>

            {{-- Form --}}
            <main class="flex min-h-full flex-col justify-center px-6 py-12 sm:px-12">
                <div class="mx-auto w-full max-w-sm">
                    <span class="mb-10 block font-display text-2xl font-bold tracking-tight text-brand-600 dark:text-white lg:hidden">
                        {{ config('app.name', 'Helm') }}
                    </span>
                    {{ $slot }}
                </div>
            </main>
        </div>
        @livewireScripts
    </body>
</html>
