<div>
    @php $money = fn ($c, $cur) => \App\Support\Money::format($c, $cur); @endphp
    <x-ui.page-header :title="'Payment from '.$payment->client->name" :subtitle="$payment->paid_on->toFormattedDateString()">
        <x-slot:actions>
            <a href="{{ route('payments.index') }}" class="btn btn-ghost">All payments</a>
            <a href="{{ route('payments.edit', $payment) }}" class="btn btn-ghost">Edit</a>
            <button wire:click="delete" wire:confirm="Delete this payment? The client balance will change." class="btn btn-ghost text-rose-600 dark:text-rose-400">Delete</button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Amount" :value="$money($payment->amount_cents, $payment->currency)" color="green" :hint="$payment->currency" />
        <x-ui.stat label="Rate" :value="$payment->exchange_rate !== null ? rtrim(rtrim((string) $payment->exchange_rate, '0'), '.') : '—'" color="blue" :hint="'To '.$local" />
        <x-ui.stat :label="'In '.$local" :value="$payment->hasLocal() ? $money($payment->local_amount_cents, $payment->local_currency) : '—'" color="purple"
            :hint="$payment->hasLocal() ? 'Converted amount' : 'No rate set'" />
    </div>

    <div class="card">
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="label">Client</dt><dd>{{ $payment->client->name }}</dd></div>
            <div><dt class="label">Project</dt><dd>{{ $payment->project?->name ?? '—' }}</dd></div>
            <div><dt class="label">Type</dt><dd>{{ ucfirst(str_replace('_', ' ', $payment->type->value)) }}</dd></div>
            <div><dt class="label">Method</dt><dd>{{ $payment->method ?: '—' }}</dd></div>
            <div><dt class="label">Paid on</dt><dd>{{ $payment->paid_on->toFormattedDateString() }}</dd></div>
            <div class="sm:col-span-2 lg:col-span-3"><dt class="label">Notes</dt><dd>{{ $payment->notes ?: '—' }}</dd></div>
        </dl>
    </div>

    <div class="card mt-6">
        <h2 class="mb-3 font-semibold">Documents</h2>
        <ul class="space-y-1 text-sm">
            @forelse ($payment->documents as $d)
                <li wire:key="doc-{{ $d->id }}"><a href="{{ route('payment-documents.download', $d->id) }}" class="link">{{ $d->original_name }}</a></li>
            @empty
                <li class="text-slate-500">No documents. Add them from the payments list.</li>
            @endforelse
        </ul>
    </div>
</div>
