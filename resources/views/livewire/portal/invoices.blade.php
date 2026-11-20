@php
    $money = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
    $sums = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $money($c, $cur))->implode(' + ') ?: $money(0, $currency);
    $filtering = $search !== '' || $from !== '' || $to !== '';
    $showTotals = auth()->user()->client->portalShows('billing_totals');
@endphp
<div>
    <x-ui.page-header title="Invoices" subtitle="The invoices we have sent you.">
        <x-slot:actions>@include('livewire.projects._view-toggle', ['live' => true])</x-slot:actions>
    </x-ui.page-header>

    <div @class(['mb-6 grid gap-4 sm:grid-cols-2', 'lg:grid-cols-4' => $showTotals, 'lg:grid-cols-3' => ! $showTotals]) data-test="portal-invoice-stats">
        <x-ui.stat label="Invoices" :value="$stats['count']" icon="document" color="slate" :hint="$stats['month_count'].' in '.now()->format('F')" />
        @if ($showTotals)
            <x-ui.stat label="Total invoiced" :value="$sums($stats['invoiced'])" icon="currency" color="blue" :hint="$stats['count'].' '.\Illuminate\Support\Str::plural('invoice', $stats['count'])" />
        @endif
        <x-ui.stat label="This month" :value="$sums($stats['month'])" icon="calendar" color="green" :hint="$stats['month_count'].' in '.now()->format('F')" />
        <x-ui.stat label="Average invoice" :value="$sums($stats['average'])" icon="document" color="purple" hint="Per invoice" />
    </div>

    <div class="card mb-6 space-y-4 p-4">
        <div>
            <label for="search" class="sr-only">Search invoices</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by invoice number..." class="input" />
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-gray-700">
            <x-ui.date-filter label="Issued" :from="$from" :to="$to" :period="$period" :years="$years" :year="$year" />
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="link text-sm">Clear all filters</button>
            @endif
        </div>
    </div>

    @if ($invoices->isEmpty())
        @if ($filtering)
            <x-ui.empty-state title="No matches" message="No invoices match your filters.">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No invoices yet" message="Invoices will appear here once they are issued." />
        @endif
    @elseif ($view === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="portal-invoices-cards">
            @foreach ($invoices as $invoice)
                <div wire:key="pi-{{ $invoice->id }}" class="card flex flex-col p-5" data-test="portal-invoice-card">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="truncate font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $invoice->number }}</a>
                        <x-icon name="file-text" class="h-5 w-5 text-slate-400" />
                    </div>
                    <p class="mt-3 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $money($invoice->total_cents, $invoice->currency) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-slate-500 dark:text-gray-400">Issued</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $invoice->issued_on->toFormattedDateString() }}</dd></div>
                        <div><dt class="text-slate-500 dark:text-gray-400">Pay by</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $invoice->due_on?->toFormattedDateString() ?? '—' }}</dd></div>
                    </dl>
                    <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-sm dark:border-gray-700">
                        <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="link" data-test="portal-invoice-view">View details</a>
                        <a href="{{ route('invoices.pdf', $invoice) }}" class="link">Download PDF</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $invoices->links() }}</div>
    @else
        <x-ui.table-card sticky data-test="portal-invoices-list">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Invoice month</th>
                        <th class="table-th">Billed for</th>
                        <th class="table-th">Pay by</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($invoices as $invoice)
                        <tr wire:key="pil-{{ $invoice->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="link font-medium">{{ $invoice->number }}</a></td>
                            <td class="table-td whitespace-nowrap">{{ $invoice->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td whitespace-nowrap" data-test="month">{{ $invoice->issued_on->format('M Y') }}</td>
                            <td class="table-td whitespace-nowrap" data-test="for-month">{{ $invoice->forMonthLabel() ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap">{{ $invoice->due_on?->toFormattedDateString() ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($invoice->total_cents, $invoice->currency) }}</td>
                            <td class="table-td whitespace-nowrap text-right">
                                <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="btn btn-ghost px-2" title="View details" aria-label="View {{ $invoice->number }}" data-test="portal-invoice-view"><x-icon name="eye" class="h-4 w-4" /></a>
                                <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-ghost px-2" title="Download PDF" aria-label="Download {{ $invoice->number }} PDF"><x-icon name="download" class="h-4 w-4" /></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $invoices->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
