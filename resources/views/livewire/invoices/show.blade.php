<div>
    <x-ui.page-header :title="'Invoice '.$invoice->number">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">All invoices</a>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn btn-ghost">Download PDF</a>
            @if ($invoice->status === 'sent' && $invoice->client->contact_email)
                <button wire:click="resend" wire:loading.attr="disabled" wire:target="resend" class="btn btn-ghost">Resend email</button>
            @endif
            @if ($invoice->status === 'draft')
                <x-ui.badge color="amber">not sent</x-ui.badge>
                <button wire:click="sendNow" wire:confirm="Send this invoice? It will count against the client's balance and be emailed." class="btn btn-primary">Send invoice</button>
            @endif
            @if ($invoice->status !== 'void')
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-ghost">Edit</a>
            @endif
            @if ($invoice->status === 'sent')
                <button wire:click="void" wire:confirm="Cancel this invoice? It stops counting in totals and its lines return to pending." class="btn btn-ghost">Cancel invoice</button>
            @elseif ($invoice->status === 'void')
                <x-ui.badge color="rose">cancelled</x-ui.badge>
            @endif
            <button wire:click="delete" wire:confirm="Delete this invoice permanently? Its lines return to pending and the client balance changes." class="btn btn-ghost text-rose-600 dark:text-rose-400">Delete</button>
        </x-slot:actions>
    </x-ui.page-header>
    @unless ($invoice->client->contact_email)<div class="mb-4"><x-ui.badge color="amber">No contact email — invoice not emailed</x-ui.badge></div>@endunless
    @if ($invoice->localTotalCents() !== null && strtoupper($invoice->currency) !== \App\Services\LocalCurrency::code())
        <p class="mb-3 text-sm text-slate-600 dark:text-gray-300">Frozen rate {{ $invoice->exchange_rate }} &middot; local total {{ \App\Support\Money::format($invoice->localTotalCents(), \App\Services\LocalCurrency::code()) }} (owner only; not on the PDF or email)</p>
    @endif
    <div class="card overflow-x-auto p-0">@include('invoices._body', ['invoice' => $invoice])</div>
</div>
