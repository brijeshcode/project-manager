@php
    $money = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
    $sums = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $money($c, $cur))->implode(' + ') ?: $money(0, $client->currency);
    $showTotals = $client->portalShows('billing_totals');
@endphp
<div>
    <x-ui.page-header title="Subscriptions" subtitle="Recurring services we provide for you." />

    @if ($awaiting)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/10 dark:text-amber-200" data-test="awaiting-banner">
            {{ $awaiting }} new {{ \Illuminate\Support\Str::plural('subscription', $awaiting) }} {{ $awaiting === 1 ? 'is' : 'are' }} waiting for your approval. Open it to review and approve.
        </div>
    @endif

    <div @class(['mb-6 grid gap-4 sm:grid-cols-2', 'lg:grid-cols-4' => $showTotals, 'lg:grid-cols-3' => ! $showTotals]) data-test="portal-subscription-stats">
        <x-ui.stat label="Active services" :value="$stats['active']" icon="refresh" color="green" :hint="$stats['paused'].' not running'" />
        <x-ui.stat label="Monthly cost" :value="$sums($stats['monthly'])" icon="currency" color="blue" hint="Active services, yearly spread per month" />
        <x-ui.stat label="Due in 30 days" :value="$stats['due_soon']" icon="calendar" color="yellow" hint="Active services falling due" />
        @if ($showTotals)
            <x-ui.stat label="Invoiced to date" :value="$sums($stats['invoiced'])" icon="check" color="purple" :hint="$stats['invoiced_count'].' '.\Illuminate\Support\Str::plural('time', $stats['invoiced_count']).' on invoices sent to you'" />
        @endif
    </div>

    <div class="card mb-6 p-4">
        <div class="-mx-1 mb-4 flex gap-1 overflow-x-auto border-b border-slate-100 px-1 pb-3 dark:border-gray-700" role="tablist" aria-label="Filter by status">
            @foreach (['' => 'All', 'active' => 'Active', 'paused' => 'Not running'] as $value => $text)
                <button type="button" wire:click="$set('status', '{{ $value }}')" role="tab" aria-selected="{{ $status === $value ? 'true' : 'false' }}"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === $value ? 'bg-brand-50 text-brand-700 dark:bg-blue-900/30 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">{{ $text }}</button>
            @endforeach
        </div>
        <label for="search" class="sr-only">Search services</label>
        <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by service or project..." class="input" />
    </div>

    @if ($charges->isEmpty())
        @if ($search !== '' || $status !== '')
            <x-ui.empty-state title="No matches" message="No services match your filters." name="refresh">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No subscriptions" message="Recurring services like hosting or maintenance will appear here." name="refresh" />
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="portal-subscriptions">
                <thead>
                    <tr>
                        <th class="table-th">Service</th>
                        <th class="table-th">Project</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Next due</th>
                        @if ($showTotals)<th class="table-th text-right">Invoiced</th>@endif
                        <th class="table-th">Status</th>
                        @if ($showTotals)<th class="table-th"><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($charges as $s)
                        @php [$badge, $color] = $s->statusBadge(); $cur = $s->project?->currency ?? $client->currency; @endphp
                        <tr wire:key="sub-{{ $s->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td">
                                <a href="{{ route('portal.subscription', $s->id) }}" wire:navigate class="link font-medium">{{ $s->description }}</a>
                                <div class="text-xs text-slate-500 dark:text-gray-400">{{ ucfirst($s->frequency->value) }}</div>
                            </td>
                            <td class="table-td">{{ $s->project?->name ?? 'All projects' }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $money($s->amount_cents, $cur) }}</span>
                                <div class="text-xs text-slate-500 dark:text-gray-400">per {{ $s->frequency->value === 'yearly' ? 'year' : 'month' }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap">
                                @if ($s->active)
                                    <span @class(['font-medium text-amber-600 dark:text-amber-400' => $s->next_due_on->lte(today()->addDays(7))])>{{ $s->next_due_on->toFormattedDateString() }}</span>
                                @else
                                    <span class="text-slate-400 dark:text-gray-500">—</span>
                                @endif
                                @php $gaps = array_filter([count($s->skipped_periods ?? []) ? count($s->skipped_periods).' skipped' : null, count($s->paused_periods ?? []) ? count($s->paused_periods).' paused' : null]); @endphp
                                @if ($gaps)<div class="text-xs text-slate-500 dark:text-gray-400" data-test="gaps">{{ implode(' · ', $gaps) }}</div>@endif
                            </td>
                            @if ($showTotals)
                                <td class="table-td whitespace-nowrap text-right tabular-nums" data-test="sub-usage">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $money($s->invoiced_cents, $cur) }}</div>
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ $s->invoiced_count }} {{ \Illuminate\Support\Str::plural('time', $s->invoiced_count) }}</div>
                                </td>
                            @endif
                            <td class="table-td"><x-ui.badge :color="$color">{{ $badge }}</x-ui.badge></td>
                            @if ($showTotals)
                                <td class="table-td whitespace-nowrap text-right"><a href="{{ route('portal.subscription', $s->id) }}" wire:navigate class="btn btn-ghost px-2" title="View details" aria-label="View {{ $s->description }}"><x-icon name="eye" class="h-4 w-4" /></a></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif
</div>
