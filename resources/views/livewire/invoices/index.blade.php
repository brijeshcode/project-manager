<div>
    <x-ui.page-header title="Invoices">
        <x-slot:actions>
            <a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-card>
        <div class="sm:col-span-2">
            <label for="search" class="sr-only">Search invoices</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by number or client..." class="input" />
        </div>
        <div>
            <label for="clientFilter" class="sr-only">Client</label>
            <select wire:model.live="clientFilter" id="clientFilter" class="select">
                <option value="">All clients</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select wire:model.live="status" id="status" class="select">
                <option value="">All statuses</option>
                <option value="sent">Sent</option>
                <option value="paid">Paid</option>
                <option value="unpaid">Unpaid</option>
                <option value="void">Void</option>
            </select>
        </div>
    </x-ui.filter-card>

    @if ($invoices->isEmpty())
        @if ($search !== '' || $status !== '' || $clientFilter !== '')
            <x-ui.empty-state title="No matches" message="No invoices match your filters.">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No invoices" message="Create one from a client's pending work." />
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th">Number</th><th class="table-th">Client</th><th class="table-th">Issued</th><th class="table-th">Total</th><th class="table-th">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($invoices as $i)
                        @php
                            $paid = $i->status !== 'void' && $i->total_cents > 0 && (int) $i->payments_sum_amount_cents >= $i->total_cents;
                            [$label, $color] = $i->status === 'void' ? ['void', 'rose'] : ($paid ? ['paid', 'green'] : ['sent', 'slate']);
                        @endphp
                        <tr wire:key="inv-{{ $i->id }}">
                            <td class="table-td"><a href="{{ route('invoices.show', $i) }}" class="link">{{ $i->number }}</a></td>
                            <td class="table-td">{{ $i->client->name }}</td>
                            <td class="table-td">{{ $i->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td">
                                {{ \App\Support\Money::format($i->total_cents, $i->currency) }}
                                @if ($i->localTotalCents() !== null && strtoupper($i->currency) !== $local)
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($i->localTotalCents(), $local) }}</div>
                                @endif
                            </td>
                            <td class="table-td"><x-ui.badge :color="$color">{{ $label }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $invoices->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
