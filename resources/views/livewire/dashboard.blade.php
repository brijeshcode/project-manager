@php
    $loc = fn (int $c) => \App\Support\Money::format($c, $money['currency']);
    $user = auth()->user();
    $first = explode(' ', trim($user->name))[0] ?? $user->name;
@endphp
<div>
    <x-ui.page-header title="{{ 'Welcome back, ' . $first }}" subtitle="Here's where things stand across your work.">
        <x-slot name="actions">
            <select wire:model.live="period" class="input w-auto" aria-label="Period">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last 12 months</option>
            </select>
            <button type="button" wire:click="refresh" class="btn btn-secondary">Refresh</button>
        </x-slot>
    </x-ui.page-header>

    @if ($money['payments_missing_rate'] > 0)
        <p class="mb-4 text-sm text-amber-700 dark:text-amber-400">{{ $money['payments_missing_rate'] }} {{ $money['payments_missing_rate'] === 1 ? 'payment has' : 'payments have' }} no exchange rate and {{ $money['payments_missing_rate'] === 1 ? 'is' : 'are' }} left out. <a href="{{ route('payments.index') }}" wire:navigate class="link">Set rates</a></p>
    @endif
    @if ($money['outstanding_missing_rate'] > 0)
        <p class="mb-4 text-sm text-amber-700 dark:text-amber-400">{{ $money['outstanding_missing_rate'] }} {{ $money['outstanding_missing_rate'] === 1 ? 'client' : 'clients' }} without a rate {{ $money['outstanding_missing_rate'] === 1 ? 'is' : 'are' }} left out of Outstanding.</p>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Received" icon="currency" color="green" :value="$loc($money['received'])" :hint="$period == 365 ? 'last 12 months' : 'last '.$period.' days'" />
        <x-ui.stat label="Outstanding" icon="credit-card" color="yellow" :value="$loc($money['outstanding'])" :hint="$owing.' clients owe'" />
        <x-ui.stat label="Active clients" icon="users" color="blue" :value="$activeClients" :hint="$clientCount.' total'" />
        <x-ui.stat label="This month" icon="document" color="purple" :value="$loc($money['this_month'])" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Projects" icon="folder" :value="$projectCount" hint="across all clients" />
        <x-ui.stat label="Open tasks" icon="check" :value="$openTasks" hint="not yet done" />
        <x-ui.stat label="Hours this week" icon="clock" :value="number_format($hoursWeek, 1)" />
    </div>

    <div class="card mt-6">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Revenue</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Payments received, in {{ $money['currency'] }} ({{ $period == 365 ? 'by month, last 12 months' : ($period == 90 ? 'by week' : 'by day') }}).</p>
        </div>
        @if ($revenue)
            <x-ui.bar-chart :points="$revenue" :currency="$money['currency']" />
        @else
            <x-ui.empty-state title="No payments in this period" message="Record a payment and it will show up here." name="credit-card" />
        @endif
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent projects</h2>
                <a href="{{ route('projects.index') }}" wire:navigate class="link text-sm font-medium">View all</a>
            </div>
            @if ($recent->isEmpty())
                <x-ui.empty-state title="No projects yet" message="Create your first client, then spin up a project for them.">
                    <x-slot name="action"><a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">Add a client</a></x-slot>
                </x-ui.empty-state>
            @else
                <div class="card divide-y divide-gray-100 p-0 dark:divide-gray-700">
                    @foreach ($recent as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $project->name }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $project->client->name }}</p>
                            </div>
                            <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Quick actions</h2>
            <div class="space-y-2">
                <a href="{{ route('projects.create') }}" wire:navigate class="card link flex items-center gap-3 p-4 text-sm font-semibold">+ New project</a>
                <a href="{{ route('clients.create') }}" wire:navigate class="card link flex items-center gap-3 p-4 text-sm font-semibold">+ New client</a>
            </div>
        </div>
    </div>
</div>
