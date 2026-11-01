<div>
    <x-ui.page-header :title="'Invoice '.$invoice->number">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">All invoices</a>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn btn-ghost">Download PDF</a>
            @if ($invoice->status !== 'void' && $invoice->client->contact_email)
                <button wire:click="resend" class="btn btn-ghost">Resend email</button>
            @endif
            @if ($invoice->status !== 'void')
                <button wire:click="void" wire:confirm="Void this invoice? Its lines return to pending." class="btn btn-ghost">Void</button>
            @else
                <x-ui.badge color="red">void</x-ui.badge>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @unless ($invoice->client->contact_email)<div class="mb-4"><x-ui.badge color="amber">No contact email — invoice not emailed</x-ui.badge></div>@endunless
    @if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="card overflow-hidden p-0">@include('invoices._body', ['invoice' => $invoice])</div>
</div>
