<div>
    <x-ui.page-header title="Invoices" subtitle="Bill clients for completed work. Payments settle the client balance.">
        <x-slot:actions>
            <a href="{{ route('payments.create') }}" class="btn btn-secondary">Record payment</a>
            <a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a>
        </x-slot:actions>
    </x-ui.page-header>

    @php $fmt = fn ($c) => $c === null ? '—' : \App\Support\Money::format($c, $stats['currency']); @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <x-ui.stat label="Total invoiced" :value="$fmt($stats['total'])" color="blue"
            :hint="$stats['count'].' sent'.($stats['missing_rate'] ? ' · '.$stats['missing_rate'].' without a rate' : '')" />
        <x-ui.stat label="Not sent" :value="$fmt($stats['draft_total'])" color="purple" :hint="$stats['draft'].' draft (estimate)'" />
    </div>

    <div class="card mb-6 p-4">
        <div class="-mx-1 mb-4 flex gap-1 overflow-x-auto border-b border-slate-100 px-1 pb-3 dark:border-gray-700" role="tablist" aria-label="Filter by status">
            @foreach (['' => 'All', 'draft' => 'Not sent', 'sent' => 'Sent', 'void' => 'Cancelled'] as $value => $text)
                <button type="button" wire:click="$set('status', '{{ $value }}')" role="tab" aria-selected="{{ $status === $value ? 'true' : 'false' }}"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === $value ? 'bg-brand-50 text-brand-700 dark:bg-blue-900/30 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">{{ $text }}</button>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="flex gap-2 sm:col-span-2">
                <label for="search" class="sr-only">Search invoices</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by number or client..." class="input" />
                @include('livewire.projects._view-toggle', ['live' => true])
            </div>
            <div>
                <label for="clientFilter" class="sr-only">Client</label>
                <select wire:model.live="clientFilter" id="clientFilter" class="select">
                    <option value="">All clients</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
        </div>
        <x-ui.date-filter label="Issued" :from="$from" :to="$to" :period="$period" class="mt-4" />
    </div>

    @if ($invoices->isEmpty())
        @if ($search !== '' || $status !== '' || $clientFilter !== '' || $from !== '' || $to !== '')
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
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Client</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Due</th>
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
                            <td class="table-td whitespace-nowrap">
                                @if ($i->due_on)
                                    <span>{{ $i->due_on->toFormattedDateString() }}</span>
                                @else
                                    <span class="text-slate-400 dark:text-gray-500">On receipt</span>
                                @endif
                            </td>
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
