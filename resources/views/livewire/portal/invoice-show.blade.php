<div>
    <div class="mb-6">
        <a href="{{ route('portal.invoices') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">&larr; Invoices</a>
    </div>

    <x-ui.page-header :title="'Invoice '.$invoice->number" :subtitle="'Issued '.$invoice->issued_on->format('d M Y').($invoice->due_on ? ' · pay by '.$invoice->due_on->format('d M Y') : '')">
        <x-slot:actions>
            <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-primary gap-1" data-test="portal-invoice-pdf"><x-icon name="file-text" /> Download PDF</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card overflow-hidden p-0" data-test="portal-invoice"><div class="overflow-x-auto">@include('invoices._body', ['invoice' => $invoice])</div></div>
</div>
