<div>
    @php
        $money = fn ($c, $cur) => \App\Support\Money::format($c, $cur);
        $foreign = strtoupper($payment->currency) !== $local;
        $method = $payment->method ? (strlen($payment->method) <= 4 ? strtoupper($payment->method) : ucfirst($payment->method)) : null;
        $dt = 'text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-gray-400';
        $dd = 'mt-1 text-sm font-medium text-gray-900 dark:text-white';
    @endphp
    <x-ui.breadcrumb :items="[['Payments', route('payments.index')], ['Payment #'.$payment->id, null]]" />
    <x-ui.page-header :title="'Payment #'.$payment->id" :subtitle="$payment->client->name.' · received '.$payment->paid_on->format('M j, Y')">
        <x-slot:actions>
            <a href="{{ route('payments.edit', $payment) }}" wire:navigate class="btn btn-secondary"><x-icon name="pencil" class="h-4 w-4" />Edit</a>
            <button type="button" wire:click="delete" wire:confirm="Delete this payment? The client balance will change." class="btn btn-ghost text-rose-600 dark:text-rose-400"><x-icon name="trash-2" class="h-4 w-4" />Delete</button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">Amount billed and settled</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-white" data-test="amount">{{ $money($payment->amount_cents, $payment->currency) }}</p>
                @if ($payment->deductions_cents)
                    <p class="mt-2 text-sm tabular-nums text-slate-600 dark:text-gray-300">TDS &amp; deductions {{ $money($payment->deductions_cents, $payment->currency) }} · cash received {{ $money($payment->amount_cents - $payment->deductions_cents, $payment->currency) }}</p>
                @endif
                @if ($foreign)
                    <p class="mt-1 text-sm tabular-nums text-slate-500 dark:text-gray-400">
                        @if ($payment->hasLocal())
                            {{ $money($payment->local_amount_cents, $payment->local_currency) }} at {{ rtrim(rtrim((string) $payment->exchange_rate, '0'), '.') }} {{ $local }} / {{ strtoupper($payment->currency) }}
                        @else
                            No exchange rate to {{ $local }} yet. <a href="{{ route('payments.edit', $payment) }}" wire:navigate class="link">Set one</a>
                        @endif
                    </p>
                @endif

                <dl class="mt-6 grid grid-cols-2 gap-6 border-t border-slate-100 pt-6 sm:grid-cols-4 dark:border-gray-700">
                    <div><dt class="{{ $dt }}">Paid on</dt><dd class="{{ $dd }}">{{ $payment->paid_on->format('M j, Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Type</dt><dd class="{{ $dd }}"><x-ui.badge>{{ ucfirst(str_replace('_', ' ', $payment->type->value)) }}</x-ui.badge></dd></div>
                    <div><dt class="{{ $dt }}">Method</dt><dd class="{{ $dd }}">{{ $method ?? '—' }}</dd></div>
                    <div class="min-w-0"><dt class="{{ $dt }}">Project</dt><dd class="{{ $dd }} truncate">
                        @if ($payment->project)<a href="{{ route('projects.show', $payment->project) }}" wire:navigate class="link">{{ $payment->project->name }}</a>@else — @endif
                    </dd></div>
                </dl>
            </div>

            <div class="card p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Notes</h2>
                @if ($payment->notes)
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-gray-300">{{ $payment->notes }}</p>
                @else
                    <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">No notes.</p>
                @endif
            </div>

            <div class="card p-6" id="documents">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Documents <span class="font-normal text-slate-400">({{ $payment->documents->count() }})</span></h2>
                    @if ($payment->documents->isNotEmpty())
                        <x-ui.badge :color="$payment->documents_visible_to_client ? 'green' : 'slate'" class="inline-flex items-center gap-1">
                            <x-icon :name="$payment->documents_visible_to_client ? 'eye' : 'eye-off'" class="h-3.5 w-3.5" />{{ $payment->documents_visible_to_client ? 'Visible to client' : 'Hidden from client' }}
                        </x-ui.badge>
                    @endif
                </div>
                @if ($payment->documents->isEmpty())
                    <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">No documents. <a href="{{ route('payments.edit', $payment) }}" wire:navigate class="link">Add them on the edit page.</a></p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($payment->documents as $d)
                            <li wire:key="doc-{{ $d->id }}" class="flex items-center gap-3 px-4 py-2.5">
                                <x-icon name="file-text" class="h-4 w-4 shrink-0 text-slate-400" />
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $d->original_name }}</span>
                                <a href="{{ route('payment-documents.download', $d->id) }}" class="btn btn-ghost px-2 text-sm" title="Download" aria-label="Download {{ $d->original_name }}"><x-icon name="arrow-up-right" class="h-4 w-4" />Open</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Client</h2>
                <a href="{{ route('clients.show', $payment->client) }}" wire:navigate class="link mt-2 block font-medium">{{ $payment->client->name }}</a>
                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-gray-700">
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">Balance</dt>
                        <dd @class(['font-semibold tabular-nums', 'text-rose-600 dark:text-rose-400' => $balance < 0, 'text-emerald-700 dark:text-emerald-400' => $balance >= 0])>{{ $money($balance, $payment->client->currency) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">Status</dt>
                        <dd><x-ui.badge :color="$balance < 0 ? 'rose' : 'green'">{{ $balance < 0 ? 'Owes' : ($balance > 0 ? 'In credit' : 'Settled') }}</x-ui.badge></dd></div>
                </dl>
                <p class="mt-4 text-xs text-slate-500 dark:text-gray-400">Payments settle the client's balance, not a single invoice.</p>
                <a href="{{ route('payments.create', ['client' => $payment->client_id]) }}" wire:navigate class="btn btn-secondary mt-4 w-full justify-center"><x-icon name="plus" class="h-4 w-4" />Record another payment</a>
            </div>
        </div>
    </div>
</div>
