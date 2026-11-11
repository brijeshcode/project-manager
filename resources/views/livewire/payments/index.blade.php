<div>
    <x-ui.page-header title="Payments" :subtitle="'Money received from clients settles their balance. Local currency: '.$local">
        <x-slot:actions>
            <a href="{{ route('payments.create', $clientFilter !== '' ? ['client' => $clientFilter] : []) }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="h-4 w-4" />Record payment</a>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $fmt = fn ($c) => $c === null ? '—' : \App\Support\Money::format($c, $stats['currency']);
        $typeLabel = fn ($v) => ucfirst(str_replace('_', ' ', $v));
        $filtering = $clientFilter !== '' || $typeFilter !== '' || $from !== '' || $to !== '';
    @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Total received" :value="$fmt($stats['total'])" color="green" icon="credit-card"
            :hint="'In '.$local.($stats['missing_rate'] ? ' · '.$stats['missing_rate'].' without a rate' : '')" />
        <x-ui.stat label="Payments" :value="$stats['count']" color="blue" icon="document"
            :hint="'From '.$stats['clients'].' '.\Illuminate\Support\Str::plural('client', $stats['clients'])" />
        <x-ui.stat label="Average payment" :value="$fmt($stats['average'])" color="purple" icon="currency" :hint="'In '.$local" />
        @if ($stats['by_type'])
            <x-ui.stat label="By type" color="yellow"
                :lines="collect($stats['by_type'])->take(3)->map(fn ($c, $t) => ['label' => $typeLabel($t), 'value' => $fmt($c)])->values()->all()" />
        @else
            <x-ui.stat label="By type" value="—" color="yellow" icon="tag" hint="No payments in view" />
        @endif
    </div>

    <div class="card mb-6 p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="clientFilter" class="sr-only">Client</label>
                <select wire:model.live="clientFilter" id="clientFilter" class="select">
                    <option value="">All clients</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="typeFilter" class="sr-only">Type</label>
                <select wire:model.live="typeFilter" id="typeFilter" class="select">
                    <option value="">All types</option>
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $typeLabel($t->value) }}</option>@endforeach
                </select>
            </div>
        </div>
        <x-ui.date-filter label="Paid" :from="$from" :to="$to" :period="$period" class="mt-4" />
        @if ($balanceClient)
            <div class="mt-4 flex flex-wrap items-baseline gap-x-2 border-t border-slate-100 pt-4 text-sm dark:border-gray-700" data-test="client-balance">
                <span class="text-slate-500 dark:text-gray-400">{{ $balanceClient->name }} balance</span>
                <strong @class(['tabular-nums', 'text-rose-600 dark:text-rose-400' => $balance < 0, 'text-emerald-700 dark:text-emerald-400' => $balance >= 0])>{{ \App\Support\Money::format($balance, $balanceClient->currency) }}</strong>
                @if ($balanceLocal !== null && strtoupper($balanceClient->currency) !== $local)
                    <span class="tabular-nums text-slate-500 dark:text-gray-400">/ {{ \App\Support\Money::format($balanceLocal, $local) }}</span>
                @endif
                <x-ui.badge :color="$balance < 0 ? 'rose' : 'green'">{{ $balance < 0 ? 'Owes' : 'In credit' }}</x-ui.badge>
            </div>
        @endif
    </div>

    @if ($payments->isEmpty())
        @if ($filtering)
            <x-ui.empty-state title="No matches" message="No payments match your filters." name="credit-card">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No payments yet" message="Record money as it arrives; it settles the client's balance." name="credit-card">
                <x-slot name="action"><a href="{{ route('payments.create') }}" wire:navigate class="btn btn-primary">Record payment</a></x-slot>
            </x-ui.empty-state>
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Date</th>
                        <th class="table-th">Client / project</th>
                        <th class="table-th">Type</th>
                        <th class="table-th hidden md:table-cell">Method</th>
                        <th class="table-th text-right">Received</th>
                        <th class="table-th hidden text-right lg:table-cell">Rate</th>
                        <th class="table-th text-right">In {{ $local }}</th>
                        <th class="table-th text-center"><span class="sr-only">Documents</span><x-icon name="file-text" class="mx-auto h-4 w-4" aria-hidden="true" /></th>
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($payments as $p)
                        @php
                            $foreign = strtoupper($p->currency) !== $local;
                            $method = $p->method ? (strlen($p->method) <= 4 ? strtoupper($p->method) : ucfirst($p->method)) : null;
                        @endphp
                        <tr wire:key="pay-{{ $p->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap text-slate-600 dark:text-gray-300">{{ $p->paid_on->format('M j, Y') }}</td>
                            <td class="table-td max-w-xs">
                                <a href="{{ route('payments.show', $p) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $p->client->name }}</a>
                                <div class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $p->project?->name ?? 'No project' }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap"><x-ui.badge>{{ $typeLabel($p->type->value) }}</x-ui.badge></td>
                            <td class="table-td hidden whitespace-nowrap text-sm text-slate-600 md:table-cell dark:text-gray-300">{{ $method ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Money::format($p->amount_cents, $p->currency) }}</td>
                            <td class="table-td hidden whitespace-nowrap text-right text-sm tabular-nums text-slate-500 lg:table-cell dark:text-gray-400">{{ $foreign && $p->hasLocal() ? rtrim(rtrim($p->exchange_rate, '0'), '.') : '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums text-slate-700 dark:text-gray-200">
                                @if (! $foreign)
                                    {{ \App\Support\Money::format($p->amount_cents, $p->currency) }}
                                @elseif ($p->hasLocal())
                                    {{ \App\Support\Money::format($p->local_amount_cents, $p->local_currency) }}
                                @else
                                    <form wire:submit="setRate({{ $p->id }})" class="flex items-center justify-end gap-1">
                                        <label for="rowRate-{{ $p->id }}" class="sr-only">Rate to {{ $local }}</label>
                                        <input wire:model="rowRate.{{ $p->id }}" id="rowRate-{{ $p->id }}" type="text" inputmode="decimal" placeholder="Rate" class="input w-20 py-1 text-xs" />
                                        <button type="submit" class="btn btn-secondary py-1 text-xs">Set rate</button>
                                    </form>
                                    @error("rowRate.{$p->id}") <p class="field-error">{{ $message }}</p> @enderror
                                @endif
                            </td>
                            <td class="table-td whitespace-nowrap text-center">
                                @if ($p->documents_count)
                                    <a href="{{ route('payments.show', $p) }}#documents" wire:navigate class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sm text-slate-500 hover:bg-slate-100 hover:text-brand-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-blue-300"
                                        title="{{ $p->documents_count }} {{ \Illuminate\Support\Str::plural('document', $p->documents_count) }}" aria-label="View {{ $p->documents_count }} {{ \Illuminate\Support\Str::plural('document', $p->documents_count) }}" data-test="docs-link">
                                        <x-icon name="file-text" class="h-4 w-4" />{{ $p->documents_count }}
                                    </a>
                                @else
                                    <span class="text-slate-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="table-td whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-0.5">
                                    <a href="{{ route('payments.show', $p) }}" wire:navigate class="btn btn-ghost px-2" title="View" aria-label="View payment"><x-icon name="eye" class="h-4 w-4" /></a>
                                    <a href="{{ route('payments.edit', $p) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit payment"><x-icon name="pencil" class="h-4 w-4" /></a>
                                    <button type="button" wire:click="deletePayment({{ $p->id }})" wire:confirm="Delete this payment? The client balance will change." class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete payment"><x-icon name="trash-2" class="h-4 w-4" /></button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $payments->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
