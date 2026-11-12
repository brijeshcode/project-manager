@php
    [$label, $color, $explain] = match ($invoice->status) {
        'draft' => ['Not sent', 'amber', 'Saved as a draft. It does not count towards the client\'s balance until you send it.'],
        'void' => ['Cancelled', 'rose', 'Cancelled. It no longer counts in totals and its work went back to pending.'],
        default => ['Sent', 'brand', 'Sent to the client and counted in their balance. Payments are recorded against the client balance, not this invoice.'],
    };
    $local = \App\Services\LocalCurrency::code();
    $open = $invoice->status !== 'void';
@endphp
<div>
    <x-ui.page-header :title="'Invoice '.$invoice->number" :subtitle="$invoice->client->name.' · issued '.$invoice->issued_on->toFormattedDateString()">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">All invoices</a>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn btn-secondary">Download PDF</a>
            @if ($open)
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-secondary">Edit</a>
            @endif
            @if ($invoice->status === 'draft')
                <button wire:click="sendNow" wire:confirm="Send this invoice? It will count against the client's balance and be emailed." class="btn btn-primary">Send invoice</button>
            @elseif ($invoice->status === 'sent')
                <a href="{{ route('payments.create', ['client' => $invoice->client_id]) }}" class="btn btn-primary">Record payment</a>
            @endif
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open" class="btn btn-ghost px-2" aria-label="More actions" aria-haspopup="menu" :aria-expanded="open"><x-icon name="ellipsis" class="h-5 w-5" /></button>
                <div x-show="open" style="display:none" @click.outside="open = false" role="menu" class="absolute right-0 z-20 mt-1 w-52 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-gray-600 dark:bg-gray-800">
                    @if ($invoice->status === 'sent' && $invoice->client->contact_email)
                        <button wire:click="resend" wire:loading.attr="disabled" wire:target="resend" @click="open = false" role="menuitem" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50 dark:text-gray-200 dark:hover:bg-gray-700">Resend email</button>
                    @endif
                    @if ($invoice->status === 'sent')
                        <button wire:click="void" wire:confirm="Cancel this invoice? It stops counting in totals and its lines return to pending." @click="open = false" role="menuitem" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50 dark:text-gray-200 dark:hover:bg-gray-700">Cancel invoice</button>
                    @endif
                    <button wire:click="delete" wire:confirm="Delete this invoice permanently? Its lines return to pending and the client balance changes." role="menuitem" class="block w-full px-4 py-2 text-left text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-gray-700">Delete</button>
                </div>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="card overflow-hidden p-0"><div class="overflow-x-auto">@include('invoices._body', ['invoice' => $invoice])</div></div>
            {{-- admin only: the client-facing body above stays untagged --}}
            @php $pass = $invoice->lines->where('pass_through', true); @endphp
            @if ($pass->isNotEmpty())
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-800/60" data-test="pass-through-lines">
                    <p class="font-medium text-slate-900 dark:text-white"><x-ui.badge>Pass-through</x-ui.badge> <span class="ml-1">{{ \App\Support\Money::format((int) $pass->sum('amount_cents'), $invoice->currency) }} of this invoice is a cost passed on to the client, not income</span></p>
                    <ul class="mt-2 list-disc pl-5 text-slate-600 dark:text-gray-300">
                        @foreach ($pass as $l)<li>{{ $l->description }} · {{ \App\Support\Money::format((int) $l->amount_cents, $invoice->currency) }}</li>@endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Status</h2>
                    <x-ui.badge :color="$color">{{ $label }}</x-ui.badge>
                </div>
                <p class="mt-2 text-sm text-slate-600 dark:text-gray-300">{{ $explain }}</p>
                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-gray-700">
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-gray-400">Total</dt><dd class="font-medium tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</dd></div>
                    @if ($invoice->due_on)
                        <div class="flex justify-between"><dt class="text-slate-500 dark:text-gray-400">Due</dt><dd class="text-gray-900 dark:text-white">{{ $invoice->due_on->toFormattedDateString() }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-gray-400">Client balance</dt><dd class="tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Money::format(max($balance, 0), $invoice->client->currency) }}</dd></div>
                    @if ($invoice->emailed_at)
                        <div class="flex justify-between"><dt class="text-slate-500 dark:text-gray-400">Emailed</dt><dd class="text-gray-900 dark:text-white">{{ $invoice->emailed_at->toFormattedDateString() }}</dd></div>
                    @endif
                </dl>
                @if ($open)
                    <div class="mt-4 border-t border-slate-100 pt-4 dark:border-gray-700">
                        <label for="statusPick" class="label">Change status</label>
                        <select wire:model.live="statusPick" id="statusPick" class="select">
                            <option value="draft">Not sent (draft)</option>
                            <option value="sent">Sent</option>
                        </select>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-gray-400">A draft is left out of the client's balance; Sent adds it and emails it once. Cancel is in the &hellip; menu.</p>
                    </div>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Pay to</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">The account printed on the invoice, PDF and email. Choose none to leave it off.</p>
                @if ($open)
                    <label for="bankAccountId" class="sr-only">Pay to</label>
                    <select wire:model.live="bankAccountId" id="bankAccountId" class="select mt-3">
                        <option value="">Don't show payment account</option>
                        @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                    </select>
                @endif
                <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">Accounts come from <a href="{{ route('billing.bank-accounts') }}" class="link">Billing &rsaquo; Bank accounts</a>. The invoice keeps a copy, so later edits to an account don't change invoices already issued.</p>
            </div>

            <div class="card p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Client</h2>
                <p class="mt-2 text-sm"><a href="{{ route('clients.show', $invoice->client) }}" class="link font-medium">{{ $invoice->client->name }}</a></p>
                @if ($invoice->client->contact_email)
                    <p class="text-sm text-slate-500 dark:text-gray-400">{{ $invoice->client->contact_email }}</p>
                @else
                    <div class="mt-2"><x-ui.badge color="amber">No contact email — invoice not emailed</x-ui.badge></div>
                @endif
                @if ($invoice->localTotalCents() !== null && strtoupper($invoice->currency) !== $local)
                    <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-gray-700 dark:text-gray-400">Frozen rate {{ $invoice->exchange_rate }} &middot; local total {{ \App\Support\Money::format($invoice->localTotalCents(), $local) }}. Only you see this; it is not on the PDF or email.</p>
                @endif
            </div>
        </div>
    </div>
</div>
