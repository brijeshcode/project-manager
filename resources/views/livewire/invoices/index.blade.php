<div>
    <x-ui.page-header title="Invoices" subtitle="Bill clients for completed work. Payments settle the client balance.">
        <x-slot:actions>
            @include('livewire.projects._view-toggle', ['live' => true])
            <x-ui.filter-button :active="in_array($status, ['draft', 'sent', 'void'], true) + ($clientFilter !== '') + ($from !== '' || $to !== '')" />
            <a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $money = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
        $sums = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $money($c, $cur))->implode(' + ') ?: $money(0, $local);
        $foreign = collect($stats['invoiced'])->keys()->contains(fn ($cur) => strtoupper($cur) !== $local);
        $noRate = $stats['missing_rate'] ? ' · '.$stats['missing_rate'].' without a rate' : '';
    @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-ui.stat label="Invoices sent" :value="$stats['count']" icon="document" color="slate"
            :hint="$stats['drafts'] ? $stats['drafts'].' '.\Illuminate\Support\Str::plural('draft', $stats['drafts']).' not sent yet' : 'No drafts waiting'" />
        <x-ui.stat label="Total invoiced" :value="$sums($stats['invoiced'])" icon="currency" color="blue"
            :hint="($foreign ? '≈ '.$money($stats['invoiced_local'], $local).' · ' : '').$stats['count'].' '.\Illuminate\Support\Str::plural('invoice', $stats['count']).$noRate" />
        <x-ui.stat label="This month" :value="$sums($stats['month'])" icon="calendar" color="green"
            :hint="$stats['month_count'].' sent in '.now()->format('F')" />
        <x-ui.stat label="Average invoice" :value="$sums($stats['average'])" icon="document" color="purple"
            :hint="($foreign && $stats['average_local'] !== null ? '≈ '.$money($stats['average_local'], $local).' · ' : '').'Per sent invoice'.$noRate" />
        <x-ui.stat label="Clients billed" :value="$stats['clients']" icon="users" color="yellow" hint="With a sent invoice" />
    </div>

    @php
        $filtering = $search !== '' || $status !== '' || $clientFilter !== '' || $from !== '' || $to !== '';
        $statuses = ['draft' => 'Not sent', 'sent' => 'Sent', 'void' => 'Cancelled'];
        $day = fn ($d) => rescue(fn () => \Carbon\Carbon::parse($d)->format('j M Y'), $d, false);
        $dated = $from !== '' || $to !== '';
        $dateChip = $period ? ['month' => 'This month', 'year' => 'This year', 'fy' => 'Financial year'][$period]
            : ($year ?: ($from !== '' && $to !== '' ? $day($from).' – '.$day($to) : ($from !== '' ? 'from '.$day($from) : 'until '.$day($to))));
    @endphp
    <x-ui.filter-panel>
        <x-slot:bar>
            <label for="search" class="sr-only">Search invoices</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by number or client..." class="input" />
        </x-slot:bar>
        <x-slot:chips>
            @isset($statuses[$status])
                <x-ui.filter-chip :label="'Status: '.$statuses[$status]" wire:click="$set('status', '')" />
            @endisset
            @if ($clientFilter !== '')
                <x-ui.filter-chip :label="'Client: '.($clients->firstWhere('id', (int) $clientFilter)?->name ?? '—')" wire:click="$set('clientFilter', '')" />
            @endif
            @if ($dated)
                <x-ui.filter-chip :label="'Issued: '.$dateChip" wire:click="setPeriod('')" />
            @endif
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="link text-sm">Clear all filters</button>
            @endif
        </x-slot:chips>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label for="status" class="sr-only">Status</label>
                <select wire:model="status" id="status" class="select">
                    @foreach (['' => 'All statuses'] + $statuses as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="clientFilter" class="sr-only">Client</label>
                <select wire:model="clientFilter" id="clientFilter" class="select">
                    <option value="">All clients</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
        </div>
        <x-ui.date-filter label="Issued" :from="$from" :to="$to" :period="$period" :years="$years" :year="$year" defer />
    </x-ui.filter-panel>

    @if ($invoices->isEmpty())
        @if ($filtering)
            <x-ui.empty-state title="No matches" message="No invoices match your filters.">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No invoices yet" message="Create one from a client's completed work.">
                <x-slot name="action"><a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a></x-slot>
            </x-ui.empty-state>
        @endif
    @elseif ($view === 'cards')
        @include('livewire.invoices._cards')
        <div class="mt-6">{{ $invoices->links() }}</div>
    @else
        <x-ui.table-card sticky>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Client</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Invoice month</th>
                        <th class="table-th">Billed for</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Status</th>
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($invoices as $i)
                        @php [$label, $color] = match ($i->status) { 'void' => ['Cancelled', 'rose'], 'draft' => ['Not sent', 'amber'], default => ['Sent', 'brand'] }; @endphp
                        <tr wire:key="inv-{{ $i->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('invoices.show', $i) }}" class="link font-medium">{{ $i->number }}</a></td>
                            <td class="table-td">{{ $i->client->name }}</td>
                            <td class="table-td whitespace-nowrap">{{ $i->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td whitespace-nowrap" data-test="month">{{ $i->issued_on->format('M Y') }}</td>
                            <td class="table-td whitespace-nowrap" data-test="for-month">{{ $i->forMonthLabel() ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ \App\Support\Money::format($i->total_cents, $i->currency) }}</span>
                                @if ($i->localTotalCents() !== null && strtoupper($i->currency) !== $local)
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($i->localTotalCents(), $local) }}</div>
                                @endif
                            </td>
                            <td class="table-td">@include('livewire.invoices._status')</td>
                            <td class="table-td whitespace-nowrap text-right">@include('livewire.invoices._actions')</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $invoices->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
