@php
    $cur = $charge->currency();
    $money = fn ($c, $code = null) => \App\Support\Money::format((int) $c, $code ?? $cur);
    $periodLabel = fn ($p) => $p === null ? '—' : (strlen($p) === 7 ? \Carbon\Carbon::createFromFormat('!Y-m', $p)->format('M Y') : $p);
    $other = strtoupper($cur) !== $local;
@endphp
<div>
    <x-ui.breadcrumb :items="[['Recurring services', route('billing.recurring')], [$charge->description, null]]" />

    <x-ui.page-header :title="$charge->description" :subtitle="$charge->client->name.' · '.($charge->project?->name ?? 'Client-wide')">
        <x-slot:actions>
            <x-ui.badge :color="$charge->active ? 'green' : 'slate'">{{ $charge->active ? 'Active' : 'Paused' }}</x-ui.badge>
            <button type="button" wire:click="toggle" class="btn btn-secondary">{{ $charge->active ? 'Pause' : 'Resume' }}</button>
            <a href="{{ route('billing.recurring.edit', $charge) }}" wire:navigate class="btn btn-primary"><x-icon name="pencil" class="h-4 w-4" /> Edit</a>
            @if ($usages->isEmpty())
                <button type="button" wire:click="delete" wire:confirm="Delete this service? Any lines it added that are not on an invoice are removed too." class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete service"><x-icon name="trash-2" class="h-4 w-4" /></button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Amount" :value="$money($charge->amount_cents)" icon="currency" color="blue" :hint="'Billed '.$charge->frequency->value.($other && $charge->local_amount_cents !== null ? ' · ≈ '.$money($charge->local_amount_cents, $local) : '')" />
        <x-ui.stat label="Next due" :value="$charge->active ? $charge->next_due_on->toFormattedDateString() : '—'" icon="calendar" color="yellow" :hint="$charge->active ? $charge->next_due_on->diffForHumans() : 'Paused'" />
        <x-ui.stat label="Times invoiced" :value="$charge->invoiced_count" icon="check" color="green" :hint="$charge->draft_count ? $charge->draft_count.' more on draft invoices' : 'On sent invoices'" />
        <x-ui.stat label="Invoiced to date" :value="$money($charge->invoiced_cents)" icon="refresh" color="purple" :hint="$other && $charge->invoiced_count ? $money($charge->invoiced_local_cents, $local) : 'Sent invoices only'" />
    </div>

    @if ($pending->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300" data-test="pending">
            {{ $pending->count() }} {{ \Illuminate\Support\Str::plural('period', $pending->count()) }} waiting to be invoiced
            ({{ $pending->map(fn ($l) => $periodLabel($l->period))->implode(', ') }}) · {{ $money($pending->sum('amount_cents')) }}.
            <a href="{{ route('invoices.create') }}" class="link font-medium">Create invoice</a>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Invoice history</h2>
    @if ($usages->isEmpty())
        <x-ui.empty-state title="Not invoiced yet" message="Each time this service falls due it adds a line you can include on the client's next invoice." name="document" />
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Billing period</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($usages as $l)
                        <tr wire:key="use-{{ $l->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40" data-test="usage-row">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('invoices.show', $l->invoice_id) }}" class="link font-medium">{{ $l->invoice->number }}</a></td>
                            <td class="table-td whitespace-nowrap">{{ $l->invoice->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td whitespace-nowrap">{{ $periodLabel($l->period) }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $money($l->amount_cents) }}</span>
                                @if ($other && $l->local_amount_cents !== null)
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ $money($l->local_amount_cents, $local) }}</div>
                                @endif
                            </td>
                            <td class="table-td"><x-ui.badge :color="$l->invoice->status === 'sent' ? 'brand' : 'amber'">{{ $l->invoice->status === 'sent' ? 'Sent' : 'Not sent' }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif
</div>
