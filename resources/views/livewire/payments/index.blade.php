<div>
    <x-ui.page-header title="Payments" subtitle="Record money received from clients. Local currency: {{ $local }}" />

    @php $fmt = fn ($c) => \App\Support\Money::format($c, $stats['currency']); @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Total received" :value="$fmt($stats['total'])" color="green"
            :hint="'In '.$local.($stats['missing_rate'] ? ' · '.$stats['missing_rate'].' without a rate' : '')" />
        <x-ui.stat label="Received this month" :value="$fmt($stats['this_month'])" color="blue" :hint="'In '.$local" />
        <x-ui.stat label="Payments" :value="$stats['count']" color="purple" hint="All time" />
        <x-ui.stat label="Average payment" :value="$fmt($stats['average'])" color="yellow" :hint="'In '.$local" />
    </div>

    <form wire:submit="save" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="client_id" class="label">Client</label>
            <select wire:model.live="client_id" id="client_id" class="select">
                <option value="">Select...</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            @error('client_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="project_id" class="label">Project (optional)</label>
            <select wire:model="project_id" id="project_id" class="select">
                <option value="">None</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            @error('project_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        @if ($client_id)
            <div>
                <label for="currency" class="label">Currency</label>
                <select wire:model.live="currency" id="currency" class="select">
                    @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                </select>
                @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="amount" class="label">Amount{{ $formCurrency ? ' ('.$formCurrency.')' : '' }}</label>
            <input wire:model="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input" />
            @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            @if ($preview)<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">≈ {{ $preview }}</p>@endif
        </div>
        @if ($client_id && $needsRate)
            <div>
                <label for="exchange_rate" class="label">Rate to {{ $local }}</label>
                <input wire:model.live.debounce.300ms="exchange_rate" id="exchange_rate" type="text" inputmode="decimal" autocomplete="off" class="input" />
                @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="paid_on" class="label">Paid on</label>
            <input wire:model="paid_on" id="paid_on" type="date" max="{{ today()->toDateString() }}" class="input" />
            @error('paid_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="type" class="label">Type</label>
            <select wire:model="type" id="type" class="select">
                @foreach ($types as $t)<option value="{{ $t->value }}">{{ ucfirst(str_replace('_', ' ', $t->value)) }}</option>@endforeach
            </select>
            @error('type') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="method" class="label">Method (optional)</label>
            <input wire:model="method" id="method" type="text" class="input" />
            @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="lg:col-span-2">
            <label for="notes" class="label">Notes (optional)</label>
            <input wire:model="notes" id="notes" type="text" class="input" />
            @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="newDocs" class="label">Documents (optional, up to 5; pdf, images, Word, Excel; 10 MB each)</label>
            <input wire:model="newDocs" id="newDocs" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx" class="input" />
            @error('newDocs') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            @error('newDocs.*') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Record payment</button></div>
    </form>

    <x-ui.filter-card>
        <div class="sm:col-span-2">
            <label for="search" class="sr-only">Search payments</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by client..." class="input" />
        </div>
        <div>
            <label for="clientFilter" class="sr-only">Client</label>
            <select wire:model.live="clientFilter" id="clientFilter" class="select">
                <option value="">All clients</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
        @if ($balanceClient)
            <p class="self-center text-sm">{{ $balanceClient->name }} balance:
                <strong class="{{ $balance < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">{{ \App\Support\Money::format($balance, $balanceClient->currency) }}</strong>
                @if ($balanceLocal !== null && strtoupper($balanceClient->currency) !== $local)
                    <span class="text-slate-500">/ {{ \App\Support\Money::format($balanceLocal, $local) }}</span>
                @endif
                <span class="text-slate-500">({{ $balance < 0 ? 'owes' : 'credit' }})</span></p>
        @endif
    </x-ui.filter-card>

    @if ($payments->isEmpty())
        @if ($search !== '' || $clientFilter !== '')
            <x-ui.empty-state title="No matches" message="No payments match your filters.">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No payments" message="Record one above." />
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th">Date</th><th class="table-th">Client</th><th class="table-th">Amount</th><th class="table-th">Type</th><th class="table-th">Project</th><th class="table-th">Notes</th><th class="table-th">Documents</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($payments as $p)
                        <tr wire:key="pay-{{ $p->id }}">
                            <td class="table-td">{{ $p->paid_on->toFormattedDateString() }}</td>
                            <td class="table-td">{{ $p->client->name }}</td>
                            <td class="table-td"><a href="{{ route('payments.show', $p) }}" class="link">{{ \App\Support\Money::format($p->amount_cents, $p->currency) }}</a>
                                @if ($p->hasLocal() && strtoupper($p->currency) !== $p->local_currency)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\Money::format($p->local_amount_cents, $p->local_currency) }} @ {{ rtrim(rtrim($p->exchange_rate, '0'), '.') }}</div>
                                @elseif (! $p->hasLocal() && strtoupper($p->currency) !== $local)
                                    <form wire:submit="setRate({{ $p->id }})" class="mt-1 flex items-center gap-1">
                                        <label for="rowRate-{{ $p->id }}" class="sr-only">Rate to {{ $local }}</label>
                                        <input wire:model="rowRate.{{ $p->id }}" id="rowRate-{{ $p->id }}" type="text" inputmode="decimal" placeholder="Rate" class="input w-24 py-1 text-xs" />
                                        <button type="submit" class="btn btn-secondary py-1 text-xs">Set rate</button>
                                    </form>
                                    @error("rowRate.{$p->id}") <p class="field-error">{{ $message }}</p> @enderror
                                @endif
                            </td>
                            <td class="table-td">{{ ucfirst(str_replace('_', ' ', $p->type->value)) }}@if ($p->method) <span class="text-slate-500">({{ $p->method }})</span>@endif</td>
                            <td class="table-td">{{ $p->project?->name }}</td>
                            <td class="table-td">{{ $p->notes }}</td>
                            <td class="table-td">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $p->documents->count() }} {{ \Illuminate\Support\Str::plural('document', $p->documents->count()) }}</span>
                                <ul class="mt-1 space-y-1 text-sm">
                                    @foreach ($p->documents as $d)
                                        <li wire:key="doc-{{ $d->id }}" class="flex items-center gap-2">
                                            <a href="{{ route('payment-documents.download', $d->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $d->original_name }}</a>
                                            <button type="button" wire:click="removeDocument({{ $d->id }})" wire:confirm="Remove this document?" class="text-xs text-rose-600 hover:underline dark:text-rose-400">Remove</button>
                                        </li>
                                    @endforeach
                                </ul>
                                <form wire:submit="addDocument({{ $p->id }})" class="mt-1 flex items-center gap-1">
                                    <label for="rowDocs-{{ $p->id }}" class="sr-only">Add document</label>
                                    <input wire:model="rowDocs.{{ $p->id }}" id="rowDocs-{{ $p->id }}" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx" class="input w-40 py-1 text-xs" />
                                    <button type="submit" class="btn btn-secondary py-1 text-xs">Add document</button>
                                </form>
                                @error("rowDocs.{$p->id}") <p class="field-error">{{ $message }}</p> @enderror
                                @error("rowDocs.{$p->id}.*") <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                            <td class="table-td whitespace-nowrap text-right">
                                <a href="{{ route('payments.show', $p) }}" class="text-sm font-medium link">View</a>
                                <a href="{{ route('payments.edit', $p) }}" class="ml-3 text-sm font-medium link">Edit</a>
                                <button type="button" wire:click="deletePayment({{ $p->id }})" wire:confirm="Delete this payment? The client balance will change." class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $payments->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
