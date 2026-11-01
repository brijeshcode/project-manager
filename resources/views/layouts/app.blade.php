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
        @php
            $u = auth()->user();
            $client = $u && $u->hasRole('client');
            $money = $u && $u->can('manage-money');
            // [label, route, active pattern, icon, visible]
            $nav = collect([
                ['Dashboard',     'dashboard',             'dashboard',                            'home',        $u && ! $client],
                ['Dashboard',     'portal.dashboard',      ['portal.dashboard', 'portal.project'], 'home',        $client],
                ['Clients',       'clients.index',         'clients.*',                            'users',       $u && $u->hasRole('super_admin')],
                ['Projects',      'projects.index',        'projects.*',                           'folder',      $u && ! $client],
                ['Invoices',      'invoices.index',        'invoices.*',                           'document',    $money],
                ['Invoices',      'portal.invoices',       'portal.invoices',                      'document',    $client],
                ['Payments',      'payments.index',        'payments.*',                           'credit-card', $money],
                ['Payments',      'portal.payments',       'portal.payments',                      'credit-card', $client],
                ['Recurring',     'billing.recurring',     'billing.recurring',                    'refresh',     $money],
                ['Bank accounts', 'billing.bank-accounts', 'billing.bank-accounts',                'bank',        $money],
            ])->filter(fn ($i) => $i[4]);

            $initials = $u ? collect(explode(' ', trim($u->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') : '?';
        @endphp

        <div x-data="{ open: false }" class="min-h-full">
            <!-- Mobile backdrop -->
            <div x-show="open" x-transition.opacity @click="open = false"
                 class="fixed inset-0 z-30 bg-gray-600/75 lg:hidden" style="display:none"></div>

            <!-- Sidebar -->
            <aside :class="open ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white
                          transition-transform duration-200 lg:translate-x-0 dark:border-gray-700 dark:bg-gray-800">
                <!-- Brand -->
                <div class="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-5 dark:border-gray-700">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4"/>
                            </svg>
                        </span>
                        <span class="font-display text-lg font-bold tracking-tight text-slate-900 dark:text-white">Helm</span>
                    </div>
                    <button @click="open = false" class="text-slate-500 lg:hidden dark:text-gray-400" aria-label="Close menu">
                        <x-nav.icon name="x" class="h-6 w-6" />
                    </button>
                </div>

                <!-- Nav -->
                <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                    <div class="space-y-1">
                        <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Workspace</p>
                        @foreach ($nav as [$label, $route, $pattern, $icon])
                            <x-nav.item :route="$route" :pattern="$pattern" :icon="$icon">{{ $label }}</x-nav.item>
                        @endforeach
                    </div>
                    <div class="space-y-1">
                        <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Settings</p>
                        <x-nav.item route="profile" icon="cog">Profile</x-nav.item>
                    </div>
                </nav>

                <!-- User -->
                <div class="border-t border-slate-200 p-3 dark:border-gray-700">
                    <div x-data="{ menu: false }" class="relative">
                        <button @click="menu = !menu" class="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-slate-100 dark:hover:bg-gray-700">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $initials }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $u?->name }}</span>
                                <span class="block truncate text-xs text-slate-500 dark:text-gray-400">{{ $u?->email }}</span>
                            </span>
                        </button>
                        <div x-show="menu" @click.outside="menu = false" x-transition style="display:none"
                             class="absolute bottom-full left-0 mb-2 w-full rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <a href="{{ route('profile') }}" wire:navigate @click="open = false"
                               class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                <x-nav.icon name="user" class="h-4 w-4" /> Your profile
                            </a>
                            <button type="button" @click="$store.theme.toggle()" role="switch" :aria-checked="$store.theme.dark"
                                    class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                <span class="flex items-center gap-2">
                                    <x-nav.icon name="moon" class="h-4 w-4" /> Dark mode
                                </span>
                                <span :class="$store.theme.dark ? 'bg-brand-600' : 'bg-slate-300'" class="relative inline-flex h-5 w-9 items-center rounded-full transition">
                                    <span :class="$store.theme.dark ? 'translate-x-4' : 'translate-x-0.5'" class="inline-block h-4 w-4 rounded-full bg-white shadow transition"></span>
                                </span>
                            </button>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                    <x-nav.icon name="logout" class="h-4 w-4" /> Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main column -->
            <div class="lg:pl-64">
                <!-- Mobile top bar -->
                <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 lg:hidden dark:border-gray-700 dark:bg-gray-800">
                    <button @click="open = true" class="btn btn-ghost -ml-2 p-2" aria-label="Open menu">
                        <x-nav.icon name="menu" class="h-6 w-6" />
                    </button>
                    <div class="min-w-0 flex-1 truncate font-display text-lg font-semibold text-slate-900 dark:text-white">
                        @isset($header){{ $header }}@else Helm @endisset
                    </div>
                </header>

                <!-- Page -->
                <main class="p-6">
                    <div class="mx-auto max-w-7xl">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
