<div>
    <x-ui.page-header :title="'Invoice '.$invoice->number">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">All invoices</a>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn btn-ghost">Download PDF</a>
            @if ($invoice->status !== 'void' && $invoice->client->contact_email)
                <button wire:click="resend" wire:loading.attr="disabled" wire:target="resend" class="btn btn-ghost">Resend email</button>
            @endif
            @if ($invoice->status !== 'void')
                <button wire:click="void" wire:confirm="Void this invoice? Its lines return to pending." class="btn btn-ghost">Void</button>
            @else
                <x-ui.badge color="rose">void</x-ui.badge>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @unless ($invoice->client->contact_email)<div class="mb-4"><x-ui.badge color="amber">No contact email — invoice not emailed</x-ui.badge></div>@endunless
    @if ($invoice->localTotalCents() !== null && strtoupper($invoice->currency) !== \App\Services\LocalCurrency::code())
        <p class="mb-3 text-sm text-slate-600 dark:text-gray-300">Frozen rate {{ $invoice->exchange_rate }} &middot; local total {{ \App\Support\Money::format($invoice->localTotalCents(), \App\Services\LocalCurrency::code()) }} (owner only; not on the PDF or email)</p>
    @endif
    <div class="card overflow-x-auto p-0">@include('invoices._body', ['invoice' => $invoice])</div>
</div>
