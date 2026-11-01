<div>
    <x-ui.page-header :title="'Invoice '.$invoice->number">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">All invoices</a>
            @if ($invoice->status !== 'void')
                <button wire:click="void" wire:confirm="Void this invoice? Its lines return to pending." class="btn btn-ghost">Void</button>
            @else
                <x-ui.badge color="red">void</x-ui.badge>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="card overflow-hidden p-0">@include('invoices._body', ['invoice' => $invoice])</div>
</div>
