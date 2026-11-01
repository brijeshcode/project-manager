@php
    $loc = fn (int $c) => \App\Support\Money::format($c, $money['currency']);
    $badge = ['paid' => 'green', 'pending' => 'amber', 'overdue' => 'rose', 'void' => 'slate'];
    $h2 = 'text-lg font-medium text-gray-900 dark:text-white';
@endphp
<div class="space-y-6">
    <x-ui.page-header title="Dashboard" subtitle="Welcome back! Here's your business overview.">
        <x-slot name="actions">
            <select wire:model.live="period" class="input w-auto" aria-label="Period">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last 12 months</option>
            </select>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="btn btn-secondary inline-flex items-center gap-2">
                <x-nav.icon name="refresh" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refresh,period" />
                Refresh
            </button>
        </x-slot>
    </x-ui.page-header>

    <div>
        @if ($money['payments_missing_rate'] > 0)
            <p class="mb-4 text-sm text-amber-700 dark:text-amber-400">{{ $money['payments_missing_rate'] }} {{ $money['payments_missing_rate'] === 1 ? 'payment has' : 'payments have' }} no exchange rate and {{ $money['payments_missing_rate'] === 1 ? 'is' : 'are' }} left out. <a href="{{ route('payments.index') }}" wire:navigate class="link">Set rates</a></p>
        @endif
        @if ($money['outstanding_missing_rate'] > 0)
            <p class="mb-4 text-sm text-amber-700 dark:text-amber-400">{{ $money['outstanding_missing_rate'] }} {{ $money['outstanding_missing_rate'] === 1 ? 'client' : 'clients' }} without a rate {{ $money['outstanding_missing_rate'] === 1 ? 'is' : 'are' }} left out of Outstanding.</p>
        @endif

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Total Revenue" icon="currency" color="green" :value="$loc($money['received'])" :hint="$period == 365 ? 'last 12 months' : 'last '.$period.' days'" />
            <x-ui.stat label="Outstanding Balance" icon="credit-card" color="yellow" :value="$loc($money['outstanding'])" :hint="$overdue.' overdue '.($overdue === 1 ? 'invoice' : 'invoices')" />
            <x-ui.stat label="Active Clients" icon="users" color="blue" :value="$activeClients" :hint="$clientCount.' total '.($clientCount === 1 ? 'client' : 'clients')" />
            <x-ui.stat label="This Month" icon="document" color="purple" :value="$loc($money['this_month'])" :hint="$sentThisMonth.' '.($sentThisMonth === 1 ? 'invoice' : 'invoices').' sent'" />
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card p-6">
            <div class="mb-4">
                <h2 class="{{ $h2 }}">Revenue Trend</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Payments received, in {{ $money['currency'] }} ({{ $period == 365 ? 'by month, last 12 months' : ($period == 90 ? 'by week' : 'by day') }}).</p>
            </div>
            @if ($revenue)
                <x-ui.line-chart :points="$revenue" :currency="$money['currency']" />
            @else
                <x-ui.empty-state title="No payments in this period" message="Record a payment and it will show up here." name="credit-card" />
            @endif
        </div>
        <div class="card p-6">
            <h2 class="{{ $h2 }} mb-4">Payment Status</h2>
            <x-ui.doughnut :counts="$statusCounts" />
        </div>
    </div>

    <div class="card p-6">
        <h2 class="{{ $h2 }} mb-4">Quick Actions</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('invoices.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-800"><x-nav.icon name="document" class="h-5 w-5" />Create Invoice</a>
            <a href="{{ route('payments.index') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-md bg-green-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"><x-nav.icon name="credit-card" class="h-5 w-5" />Record Payment</a>
            <a href="{{ route('clients.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-md bg-purple-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-purple-700 dark:bg-purple-700 dark:hover:bg-purple-800"><x-nav.icon name="user-plus" class="h-5 w-5" />Add Client</a>
            <a href="{{ route('projects.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-md bg-orange-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-orange-700 dark:bg-orange-700 dark:hover:bg-orange-800"><x-nav.icon name="folder-plus" class="h-5 w-5" />Add Project</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="{{ $h2 }}">Recent Invoices</h2>
                <a href="{{ route('invoices.index') }}" wire:navigate class="link text-sm font-medium">View all</a>
            </div>
            @forelse ($recentInvoices as $inv)
                @php $state = \App\Support\DashboardMoney::invoiceState($inv); @endphp
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-3 last:border-0 dark:border-gray-700">
                    <div class="min-w-0">
                        <a href="{{ route('invoices.show', $inv) }}" wire:navigate class="link text-sm font-semibold">{{ $inv->number }}</a>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $inv->client->name }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Money::format($inv->total_cents, $inv->currency) }}</span>
                        <x-ui.badge :color="$badge[$state]">{{ ucfirst($state) }}</x-ui.badge>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-gray-500 dark:text-gray-400">
                    <x-nav.icon name="document" class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                    <p class="mt-2 text-sm">No recent invoices</p>
                </div>
            @endforelse
        </div>

        <div class="card p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="{{ $h2 }}">Top Clients</h2>
                <a href="{{ route('clients.index') }}" wire:navigate class="link text-sm font-medium">View all</a>
            </div>
            <div class="space-y-3">
                @forelse ($topClients as $row)
                    <div class="flex items-center justify-between gap-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white"><x-nav.icon name="user" class="h-5 w-5" /></div>
                            <div class="min-w-0">
                                <a href="{{ route('clients.show', $row['client']) }}" wire:navigate class="block truncate text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $row['client']->name }}</a>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['invoices'] }} {{ $row['invoices'] === 1 ? 'invoice' : 'invoices' }}</p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $loc($row['revenue']) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Outstanding: {{ $row['outstanding'] === null ? '—' : $loc($row['outstanding']) }}</p>
                        </div>
                    </div>
                @empty
                    <div class="py-10 text-center text-gray-500 dark:text-gray-400">
                        <x-nav.icon name="users" class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                        <p class="mt-2 text-sm">No clients yet</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card p-6">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="{{ $h2 }}">Upcoming Due Dates</h2>
            <a href="{{ route('invoices.index', ['status' => 'sent']) }}" wire:navigate class="link text-sm font-medium">View sent invoices</a>
        </div>
        @forelse ($upcoming as $inv)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-3 last:border-0 dark:border-gray-700">
                <div class="min-w-0">
                    <a href="{{ route('invoices.show', $inv) }}" wire:navigate class="link text-sm font-semibold">{{ $inv->number }}</a>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $inv->client->name }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Money::format($inv->total_cents, $inv->currency) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Due {{ $inv->due_on->format('M j, Y') }}</p>
                </div>
            </div>
        @empty
            <div class="py-10 text-center text-gray-500 dark:text-gray-400">
                <x-nav.icon name="calendar" class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-sm">No upcoming due dates</p>
            </div>
        @endforelse
    </div>

    <div>
        <h2 class="{{ $h2 }} mb-3">Work</h2>
        <div class="grid gap-6 sm:grid-cols-3">
            <x-ui.stat label="Projects" icon="folder" :value="$projectCount" hint="across all clients" />
            <x-ui.stat label="Open tasks" icon="check" :value="$openTasks" hint="not yet done" />
            <x-ui.stat label="Hours this week" icon="clock" :value="number_format($hoursWeek, 1)" />
        </div>

        <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="{{ $h2 }}">Recent projects</h3>
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
    </div>
</div>
