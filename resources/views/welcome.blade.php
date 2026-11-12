<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @include('layouts._theme-script')

        <title>{{ config('app.name', 'Helm') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            .helm-wheel { animation: helm-turn 140s linear infinite; }
            @keyframes helm-turn { to { transform: rotate(360deg); } }
            @media (prefers-reduced-motion: reduce) { .helm-wheel { animation: none; } }
        </style>
    </head>
    @php($app = config('app.name', 'Helm'))
    <body class="min-h-full bg-white font-sans antialiased text-slate-800 dark:bg-gray-900 dark:text-gray-200">
        {{-- Hero: the navy brand panel from the sign-in page, widened to the whole fold --}}
        <section class="relative overflow-hidden bg-[#0b1b3a] text-white">
            <svg class="helm-wheel pointer-events-none absolute -right-48 top-1/2 h-[46rem] w-[46rem] -translate-y-1/2 text-brand-400/20 sm:-right-24 lg:right-0" viewBox="0 0 200 200" fill="none" stroke="currentColor" aria-hidden="true">
                <circle cx="100" cy="100" r="92" stroke-width="1"/>
                <circle cx="100" cy="100" r="64" stroke-width="6"/>
                <circle cx="100" cy="100" r="18" stroke-width="1"/>
                @for ($i = 0; $i < 8; $i++)
                    <line x1="100" y1="8" x2="100" y2="82" stroke-width="1" transform="rotate({{ $i * 45 }} 100 100)"/>
                    <circle cx="100" cy="4" r="5" stroke-width="1" transform="rotate({{ $i * 45 }} 100 100)"/>
                @endfor
            </svg>

            <header class="relative mx-auto flex max-w-6xl items-center justify-between px-4 py-6 sm:px-6">
                <span class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4"/></svg>
                    </span>
                    <span class="font-display text-xl font-bold tracking-tight">{{ $app }}</span>
                </span>
                <nav class="flex items-center gap-3">
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-blue-100/80 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">Create account</a>
                    @endif
                    <a href="{{ route('login') }}" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-semibold hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400" data-test="header-login">Log in</a>
                </nav>
            </header>

            <div class="relative mx-auto grid max-w-6xl gap-12 px-4 pb-20 pt-10 sm:px-6 lg:grid-cols-[1.15fr_1fr] lg:items-center lg:pb-28 lg:pt-16">
                <div>
                    <p class="text-sm font-medium uppercase tracking-[0.2em] text-brand-300">Projects · billing · client portal</p>
                    <h1 class="mt-4 font-display text-4xl font-semibold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">
                        Hold the course on every project.
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-blue-100/75">
                        Your team plans sprints and moves cards on the board. Your clients follow progress, read invoices and check payments in their own portal. Everyone looks at the same numbers.
                    </p>
                    <div class="mt-9 flex flex-wrap items-center gap-4">
                        <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-brand-900/40 hover:bg-brand-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#0b1b3a]" data-test="hero-login">
                            Log in
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                        <span class="text-sm text-blue-100/60">Team members and clients use the same sign-in.</span>
                    </div>
                </div>

                {{-- Product glimpse: what a client sees on their dashboard --}}
                <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm" aria-hidden="true">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-display font-semibold">Storefront rebuild</span>
                        <span class="rounded-full bg-emerald-400/15 px-2.5 py-0.5 text-xs font-medium text-emerald-300">Active</span>
                    </div>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/10"><div class="h-full w-[68%] rounded-full bg-brand-400"></div></div>
                    <div class="mt-2 flex justify-between text-xs text-blue-100/60"><span>68% complete</span><span>Due 14 Dec</span></div>
                    <dl class="mt-6 grid grid-cols-3 gap-3 text-center">
                        @foreach ([['Modules', '3 / 5'], ['Tasks', '21 / 31'], ['Sprint', '4 days']] as [$k, $v])
                            <div class="rounded-lg bg-white/5 px-2 py-3">
                                <dt class="text-[11px] uppercase tracking-wide text-blue-100/50">{{ $k }}</dt>
                                <dd class="mt-1 font-display text-lg font-semibold tabular-nums">{{ $v }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-6 space-y-2 border-t border-white/10 pt-5 text-sm">
                        <div class="flex justify-between"><span class="text-blue-100/70">Invoice INV-0042</span><span class="tabular-nums">$4,400.00</span></div>
                        <div class="flex justify-between"><span class="text-blue-100/70">Payment received</span><span class="tabular-nums text-emerald-300">−$2,200.00</span></div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Who it's for --}}
        <section class="mx-auto grid max-w-6xl gap-6 px-4 py-20 sm:px-6 md:grid-cols-2">
            @php($groups = [
                'For your team' => [
                    ['Boards and sprints', 'Drag tasks across columns, plan sprints and see what is due this week.'],
                    ['Modules and budgets', 'Break each project into modules with their own budget and deadline.'],
                    ['Invoices and payments', 'Bill from finished work, record payments and see each client\'s balance.'],
                ],
                'For your clients' => [
                    ['Live progress', 'Every project shows how much is done, what is planned and when it is due.'],
                    ['Requests and approvals', 'Clients request tasks, comment on work and approve what is finished.'],
                    ['Statement and subscriptions', 'Invoices, payments, the running balance and recurring services in one place.'],
                ],
            ])
            @foreach ($groups as $title => $items)
                <div class="rounded-2xl border border-slate-200 p-8 dark:border-gray-700 dark:bg-gray-800/40">
                    <h2 class="font-display text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h2>
                    <ul class="mt-6 space-y-5">
                        @foreach ($items as [$name, $text])
                            <li class="flex gap-3">
                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span>
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $name }}</p>
                                    <p class="mt-0.5 text-sm leading-relaxed text-slate-600 dark:text-gray-400">{{ $text }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>

        <footer class="border-t border-slate-200 dark:border-gray-800">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-slate-500 sm:flex-row sm:px-6 dark:text-gray-400">
                <span>&copy; {{ date('Y') }} {{ $app }}</span>
                <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Log in</a>
            </div>
        </footer>
    </body>
</html>
