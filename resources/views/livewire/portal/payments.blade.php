@php
    $money = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
    $sums = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $money($c, $cur))->implode(' + ') ?: $money(0, $currency);
    $typeLabel = fn ($v) => ucfirst(str_replace('_', ' ', $v));
    $filtering = $typeFilter !== '' || $from !== '' || $to !== '';
@endphp
<div>
    <x-ui.page-header title="Payments" subtitle="Payments we have received from you.">
        <x-slot:actions>@include('livewire.projects._view-toggle', ['live' => true])</x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="portal-payment-stats">
        <x-ui.stat label="Payments" :value="$stats['count']" color="blue" icon="document" :hint="$filtering ? 'In this view' : 'All time'" />
        <x-ui.stat label="Total paid" :value="$sums($stats['total'])" color="green" icon="credit-card" hint="Received from you" />
        <x-ui.stat label="Average payment" :value="$sums($stats['average'])" color="purple" icon="currency" hint="Per payment" />
        @if ($stats['by_type'])
            <x-ui.stat label="By type" color="yellow"
                :lines="collect($stats['by_type'])->take(3)->map(fn ($byCur, $t) => ['label' => $typeLabel($t), 'value' => $sums($byCur)])->values()->all()" />
        @else
            <x-ui.stat label="By type" value="—" color="yellow" icon="tag" hint="No payments in view" />
        @endif
    </div>

    <div class="card mb-6 space-y-4 p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="typeFilter" class="sr-only">Type</label>
                <select wire:model.live="typeFilter" id="typeFilter" class="select">
                    <option value="">All types</option>
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $typeLabel($t->value) }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-gray-700">
            <x-ui.date-filter label="Paid" :from="$from" :to="$to" :period="$period" :years="$years" :year="$year" />
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="link text-sm">Clear all filters</button>
            @endif
        </div>
    </div>

    @if ($payments->isEmpty())
        @if ($filtering)
            <x-ui.empty-state title="No matches" message="No payments match your filters." name="credit-card">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No payments yet" message="Payments will appear here once recorded." name="credit-card" />
        @endif
    @elseif ($view === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="portal-payments-cards">
            @foreach ($payments as $payment)
                <div wire:key="pp-{{ $payment->id }}" class="card flex flex-col p-5" data-test="portal-payment-card">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('portal.payment', $payment) }}" wire:navigate class="font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white" data-test="portal-payment-view">{{ $payment->paid_on->toFormattedDateString() }}</a>
                        <x-ui.badge>{{ $typeLabel($payment->type->value) }}</x-ui.badge>
                    </div>
                    <p class="mt-3 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $money($payment->amount_cents, $payment->currency) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-slate-500 dark:text-gray-400">Method</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $payment->method ?: '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-slate-500 dark:text-gray-400">Project</dt><dd class="mt-0.5 truncate font-semibold text-slate-900 dark:text-white">{{ $payment->project?->name ?? '—' }}</dd></div>
                    </dl>
                    @if ($payment->documents->isNotEmpty())
                        <div class="mt-3 space-y-1">
                            @foreach ($payment->documents as $d)
                                <button type="button" wire:click="download({{ $d->id }})" wire:key="pcdoc-{{ $d->id }}" class="flex max-w-full items-center gap-1 text-left text-sm link" title="Download {{ $d->original_name }}"><x-icon name="file-text" class="h-4 w-4" /><span class="truncate">{{ $d->original_name }}</span></button>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-gray-700">
                        <a href="{{ route('portal.payment', $payment) }}" wire:navigate class="link">View details</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $payments->links() }}</div>
    @else
        <x-ui.table-card sticky data-test="portal-payments-list">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Date</th>
                        <th class="table-th">Paid month</th>
                        <th class="table-th">Paid for</th>
                        <th class="table-th">Project</th>
                        <th class="table-th">Type</th>
                        <th class="table-th hidden md:table-cell">Method</th>
                        <th class="table-th text-right">Amount</th>
                        @if ($hasDocs)<th class="table-th">Documents</th>@endif
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($payments as $payment)
                        <tr wire:key="ppl-{{ $payment->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('portal.payment', $payment) }}" wire:navigate class="link font-medium" data-test="portal-payment-view">{{ $payment->paid_on->toFormattedDateString() }}</a></td>
                            <td class="table-td whitespace-nowrap" data-test="month">{{ $payment->paid_on->format('M Y') }}</td>
                            <td class="table-td whitespace-nowrap" data-test="for-month">{{ $payment->forMonthLabel() ?? '—' }}</td>
                            <td class="table-td max-w-xs truncate">{{ $payment->project?->name ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap"><x-ui.badge>{{ $typeLabel($payment->type->value) }}</x-ui.badge></td>
                            <td class="table-td hidden whitespace-nowrap text-slate-600 md:table-cell dark:text-gray-300">{{ $payment->method ?: '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($payment->amount_cents, $payment->currency) }}</td>
                            @if ($hasDocs)
                                <td class="table-td">
                                    @foreach ($payment->documents as $d)
                                        <button type="button" wire:click="download({{ $d->id }})" wire:key="pdoc-{{ $d->id }}" class="flex items-center gap-1 text-left text-sm link" title="Download {{ $d->original_name }}"><x-icon name="file-text" class="h-4 w-4" />{{ $d->original_name }}</button>
                                    @endforeach
                                </td>
                            @endif
                            <td class="table-td whitespace-nowrap text-right"><a href="{{ route('portal.payment', $payment) }}" wire:navigate class="btn btn-ghost px-2" title="View details" aria-label="View payment"><x-icon name="eye" class="h-4 w-4" /></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $payments->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
