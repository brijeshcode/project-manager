{{-- Ledger: sent invoices debit, payments credit. Balance = paid − invoiced, same sign as "Current Balance" above. --}}
@php
    $bal = fn (int $c) => $c < 0 ? 'text-red-600 dark:text-red-400' : ($c > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-white');
    $balNote = fn (int $c) => $c < 0 ? 'Owing' : ($c > 0 ? 'In credit' : 'Settled');
    $filtered = $from !== '' || $to !== '';
    $portal ??= false;
@endphp
<div class="space-y-4">
    @php $dates = ['label' => 'Date', 'id' => 'statement', 'method' => 'setPeriod', 'fromModel' => 'from', 'toModel' => 'to', 'from' => $from, 'to' => $to, 'period' => $statement['period']]; @endphp
    <x-ui.filter-panel for="client-statement" class="!mb-0">
        <x-slot:bar>
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Amounts in {{ $client->currency }}. Balance = payments − sent invoices; negative means {{ $portal ? 'you owe us' : 'the client owes you' }}.</p>
                <x-ui.filter-button for="client-statement" :active="(int) $filtered" />
            </div>
        </x-slot:bar>
        @include('livewire.clients._date-filter', $dates)
        <x-slot:chips>@include('livewire.clients._date-filter', $dates + ['chip' => true])</x-slot:chips>
    </x-ui.filter-panel>

    @if ($statement['rows']->isEmpty() && $statement['opening'] === 0)
        <x-ui.empty-state :title="$filtered ? 'Nothing in this period' : 'No transactions yet'" :message="$filtered ? 'No sent invoices or payments fall in these dates.' : 'Sent invoices and payments for this client will appear here.'" name="document" />
    @else
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="statement">
                <thead>
                    <tr>
                        <th class="table-th"><button type="button" wire:click="toggleDir" class="inline-flex items-center gap-1 uppercase hover:text-gray-900 dark:hover:text-white" title="{{ $dir === 'desc' ? 'Newest first' : 'Oldest first' }}" aria-label="Sort by date, currently {{ $dir === 'desc' ? 'newest first' : 'oldest first' }}" data-test="statement-dir">Date <span aria-hidden="true">{{ $dir === 'desc' ? '↓' : '↑' }}</span></button></th><th class="table-th">Type</th><th class="table-th">Reference</th><th class="table-th">Description</th>
                        <th class="table-th text-right">Debit</th><th class="table-th text-right">Credit</th><th class="table-th text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @if ($dir === 'asc')
                    <tr class="bg-slate-50 dark:bg-gray-800/50">
                        <td class="table-td text-gray-500 dark:text-gray-400">{{ $filtered && $from !== '' ? \Illuminate\Support\Carbon::parse($from)->format('M j, Y') : '' }}</td>
                        <td class="table-td font-medium text-gray-700 dark:text-gray-300" colspan="5">Opening balance</td>
                        <td class="table-td text-right font-semibold tabular-nums {{ $bal($statement['opening']) }}">{{ \App\Support\Money::format($statement['opening'], $client->currency) }}</td>
                    </tr>
                    @endif
                    @foreach ($statement['rows'] as $r)
                        @php $m = $r['model']; $inv = $r['type'] === 'invoice'; @endphp
                        <tr wire:key="st-{{ $r['type'] }}-{{ $m->id }}">
                            <td class="table-td whitespace-nowrap">{{ $r['date']?->format('M j, Y') }}</td>
                            <td class="table-td">
                                <x-ui.badge :color="$inv ? 'amber' : 'green'" class="inline-flex items-center gap-1">
                                    <x-nav.icon :name="$inv ? 'document' : 'credit-card'" class="h-3.5 w-3.5" />{{ $inv ? 'Invoice' : 'Payment' }}
                                </x-ui.badge>
                            </td>
                            <td class="table-td whitespace-nowrap">
                                @if ($inv)
                                    <a href="{{ route($portal ? 'portal.invoice' : 'invoices.show', $m) }}" wire:navigate class="link font-medium">{{ $m->number }}</a>
                                @else
                                    <a href="{{ route($portal ? 'portal.payment' : 'payments.show', $m) }}" wire:navigate class="link font-medium">Payment #{{ $m->id }}</a>
                                @endif
                            </td>
                            <td class="table-td text-sm text-gray-600 dark:text-gray-300">
                                @if ($inv)
                                    {{ $m->due_on ? 'Due '.$m->due_on->format('M j, Y') : 'Invoice issued' }}
                                @else
                                    {{ collect([str_replace('_', ' ', ucfirst($m->type->value)), $m->method, $m->project?->name])->filter()->implode(' · ') }}
                                @endif
                                @if ($r['foreign'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\Money::format($r['cents'], $r['currency']) }}{{ $r['amount'] === null ? ' — not counted: no exchange rate' : '' }}</div>
                                @endif
                            </td>
                            <td class="table-td text-right tabular-nums">{{ $inv && $r['amount'] !== null ? \App\Support\Money::format($r['amount'], $client->currency) : '' }}</td>
                            <td class="table-td text-right tabular-nums">{{ ! $inv && $r['amount'] !== null ? \App\Support\Money::format($r['amount'], $client->currency) : '' }}</td>
                            <td class="table-td text-right font-medium tabular-nums {{ $bal($r['balance']) }}">{{ \App\Support\Money::format($r['balance'], $client->currency) }}</td>
                        </tr>
                    @endforeach
                    @if ($dir === 'desc')
                    <tr class="bg-slate-50 dark:bg-gray-800/50">
                        <td class="table-td text-gray-500 dark:text-gray-400">{{ $filtered && $from !== '' ? \Illuminate\Support\Carbon::parse($from)->format('M j, Y') : '' }}</td>
                        <td class="table-td font-medium text-gray-700 dark:text-gray-300" colspan="5">Opening balance</td>
                        <td class="table-td text-right font-semibold tabular-nums {{ $bal($statement['opening']) }}">{{ \App\Support\Money::format($statement['opening'], $client->currency) }}</td>
                    </tr>
                    @endif
                </tbody>
                <tfoot class="border-t-2 border-slate-200 dark:border-gray-600">
                    <tr>
                        <td class="table-td font-semibold text-gray-900 dark:text-white" colspan="4">Closing balance <span class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">({{ $balNote($statement['closing']) }})</span></td>
                        <td class="table-td text-right font-semibold tabular-nums text-gray-900 dark:text-white" data-test="statement-debit">{{ \App\Support\Money::format($statement['debit'], $client->currency) }}</td>
                        <td class="table-td text-right font-semibold tabular-nums text-gray-900 dark:text-white" data-test="statement-credit">{{ \App\Support\Money::format($statement['credit'], $client->currency) }}</td>
                        <td class="table-td text-right font-bold tabular-nums {{ $bal($statement['closing']) }}" data-test="statement-closing">{{ \App\Support\Money::format($statement['closing'], $client->currency) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
