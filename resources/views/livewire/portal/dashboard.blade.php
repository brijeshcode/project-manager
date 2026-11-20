@php
    $tasksTotal = $stats->sum('tasks');
    $tasksDone = $stats->sum('tasksDone');
    $showBalance = auth()->user()->client->portalShows('statement');
    $showPayments = auth()->user()->client->portalShows('payments');
@endphp
<div class="space-y-6">
    <x-ui.page-header title="Dashboard" subtitle="Where your projects and account stand today." />

    <div @class(['grid gap-4 sm:grid-cols-2', 'xl:grid-cols-4' => $showBalance, 'xl:grid-cols-3' => ! $showBalance])>
        @if ($showBalance)
            <x-ui.stat label="Account balance" icon="currency" color="blue"
                :value="$balance < 0 ? 'You owe '.\App\Support\Money::format(-$balance, $currency) : ($balance > 0 ? 'Credit '.\App\Support\Money::format($balance, $currency) : 'All settled')" />
        @endif
        <x-ui.stat label="Active projects" icon="folder" color="purple" :value="$projects->where('status', 'active')->count()" />
        <x-ui.stat label="Tasks completed" icon="check" color="green" :value="$tasksDone.' of '.$tasksTotal" />
        <x-ui.stat label="Active subscriptions" icon="refresh" color="yellow" :value="$activeSubs" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card data-test="dash-projects">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Your projects</h2>
                <a href="{{ route('portal.projects') }}" wire:navigate class="link text-sm">View all</a>
            </div>
            @forelse ($projects as $project)
                <a href="{{ route('portal.project', $project) }}" wire:navigate wire:key="dp-{{ $project->id }}" class="-mx-2 block rounded-lg px-2 py-2.5 hover:bg-slate-50 dark:hover:bg-gray-700/40">
                    <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                        <span class="truncate font-medium text-slate-900 dark:text-white">{{ $project->name }}</span>
                        <span class="shrink-0 text-xs text-slate-500 dark:text-gray-400">{{ $project->deadline ? 'Due '.$project->deadline->format('M j, Y') : 'No deadline' }}</span>
                    </div>
                    <x-ui.progress :pct="$stats[$project->id]['pct']" />
                    @if (($expected[$project->id] ?? 0) > 0)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Expected: <span class="font-semibold tabular-nums">{{ \App\Support\Money::format($expected[$project->id], $currency) }}</span> (forecast, not yet invoiced)</p>
                    @endif
                </a>
            @empty
                <p class="text-sm text-slate-500 dark:text-gray-400">Your projects will appear here as soon as they're set up.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card data-test="dash-approvals">
            <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Waiting for your approval</h2>
            @forelse ($approvals as $task)
                <a href="{{ route('portal.project', ['project' => $task->project_id, 'tab' => 'board']) }}" wire:navigate wire:key="da-{{ $task->id }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50 dark:hover:bg-gray-700/40">
                    <span class="min-w-0 truncate"><x-ui.task-code :task="$task" class="mr-1" /><span class="text-slate-800 dark:text-gray-200">{{ $task->title }}</span></span>
                    <span class="shrink-0 text-xs text-slate-500 dark:text-gray-400">{{ $task->project->name }}</span>
                </a>
            @empty
                <p class="text-sm text-slate-500 dark:text-gray-400">Nothing waiting. Completed work you can approve will show here.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card data-test="dash-deadlines">
            <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Upcoming deadlines <span class="font-normal text-slate-500 dark:text-gray-400">(next 30 days)</span></h2>
            @forelse ($deadlines as $d)
                <div class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span class="min-w-0 truncate">
                        @if ($d['task'])<x-ui.task-code :task="$d['task']" class="mr-1" />@else<x-ui.badge color="slate" class="mr-1">Module</x-ui.badge>@endif
                        <span class="text-slate-800 dark:text-gray-200">{{ $d['title'] }}</span>
                        <span class="text-xs text-slate-500 dark:text-gray-400">· {{ $d['project']->name }}</span>
                    </span>
                    <span class="shrink-0 text-xs font-medium text-slate-700 dark:text-gray-200">{{ $d['date']->format('M j') }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-gray-400">No deadlines in the next 30 days.</p>
            @endforelse
        </x-ui.card>

        <div @class(['grid gap-6', 'sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2' => $showPayments])>
            <x-ui.card data-test="dash-invoices">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Recent invoices</h2>
                    <a href="{{ route('portal.invoices') }}" wire:navigate class="link text-sm">All</a>
                </div>
                @forelse ($invoices as $invoice)
                    <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate wire:key="di-{{ $invoice->id }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50 dark:hover:bg-gray-700/40">
                        <span><span class="font-medium text-slate-900 dark:text-white">{{ $invoice->number }}</span> <span class="text-xs text-slate-500 dark:text-gray-400">{{ $invoice->issued_on->format('M j') }}</span></span>
                        <span class="tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500 dark:text-gray-400">No invoices yet.</p>
                @endforelse
            </x-ui.card>

            @if ($showPayments)
            <x-ui.card data-test="dash-payments">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Recent payments</h2>
                    <a href="{{ route('portal.payments') }}" wire:navigate class="link text-sm">All</a>
                </div>
                @forelse ($payments as $payment)
                    <a href="{{ route('portal.payment', $payment) }}" wire:navigate wire:key="dpay-{{ $payment->id }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50 dark:hover:bg-gray-700/40">
                        <span class="text-slate-700 dark:text-gray-200">{{ $payment->paid_on->format('M j, Y') }}</span>
                        <span class="tabular-nums">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500 dark:text-gray-400">No payments yet.</p>
                @endforelse
            </x-ui.card>
            @endif
        </div>
    </div>
</div>
