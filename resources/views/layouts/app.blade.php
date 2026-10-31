<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Helm') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="h-full font-sans text-slate-800 antialiased">
        @php
            $u = auth()->user();
            $nav = collect([
                ['label' => 'Dashboard', 'route' => 'dashboard',      'pattern' => 'dashboard',  'icon' => 'home',     'show' => true],
                ['label' => 'Projects',  'route' => 'projects.index', 'pattern' => 'projects.*', 'icon' => 'folder',   'show' => $u && ! $u->hasRole('client')],
                ['label' => 'Clients',   'route' => 'clients.index',  'pattern' => 'clients.*',  'icon' => 'building', 'show' => $u && $u->hasRole('super_admin')],
                ['label' => 'Billing',   'route' => 'billing.recurring','pattern' => 'billing.*', 'icon' => 'building', 'show' => $u && $u->can('manage-money')],
                ['label' => 'My projects','route' => 'portal.dashboard','pattern' => 'portal.*',  'icon' => 'folder',   'show' => $u && $u->hasRole('client')],
            ])->where('show', true);

            $icons = [
                'home'     => '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>',
                'folder'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>',
                'building' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>',
            ];

            $initials = $u ? collect(explode(' ', trim($u->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') : '?';
        @endphp

        <div x-data="{ open: false }" class="min-h-full">
            <!-- Mobile backdrop -->
            <div x-show="open" x-transition.opacity @click="open = false"
                 class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" style="display:none"></div>

            <!-- Sidebar -->
            <aside :class="open ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white
                          transition-transform duration-200 lg:translate-x-0">
                <!-- Brand -->
                <div class="flex h-16 items-center gap-2.5 px-5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4"/>
                        </svg>
                    </span>
                    <span class="font-display text-lg font-bold tracking-tight text-slate-900">Helm</span>
                </div>

                <!-- Nav -->
                <nav class="flex-1 space-y-1 px-3 py-4">
                    <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Workspace</p>
                    @foreach ($nav as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                           class="nav-item {{ request()->routeIs($item['pattern']) ? 'nav-item-active' : '' }}">
                            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                {!! $icons[$item['icon']] !!}
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <!-- User -->
                <div class="border-t border-slate-200 p-3">
                    <div x-data="{ menu: false }" class="relative">
                        <button @click="menu = !menu" class="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-slate-100">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">{{ $initials }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900">{{ $u?->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $u?->getRoleNames()->map(fn($r) => str_replace('_',' ',$r))->implode(', ') ?: $u?->email }}</span>
                            </span>
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a.75.75 0 0 1 .55.24l3.25 3.5a.75.75 0 1 1-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 0 1-1.1-1.02l3.25-3.5A.75.75 0 0 1 10 3Zm-3.76 9.2a.75.75 0 0 1 1.06.04l2.7 2.908 2.7-2.908a.75.75 0 1 1 1.1 1.02l-3.25 3.5a.75.75 0 0 1-1.1 0l-3.25-3.5a.75.75 0 0 1 .04-1.06Z" clip-rule="evenodd"/></svg>
                        </button>
                        <div x-show="menu" @click.outside="menu = false" x-transition style="display:none"
                             class="absolute bottom-full left-0 mb-2 w-full rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                            <a href="{{ route('profile') }}" wire:navigate class="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">Profile</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100">Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main column -->
            <div class="lg:pl-64">
                <!-- Topbar -->
                <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/80 px-4 backdrop-blur sm:px-6 lg:px-8">
                    <button @click="open = true" class="btn btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                    </button>
                    <div class="min-w-0 flex-1">
                        @isset($header)
                            <div class="truncate font-display text-lg font-semibold text-slate-900">{{ $header }}</div>
                        @endisset
                    </div>
                </header>

                <!-- Page -->
                <main class="px-4 py-8 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
