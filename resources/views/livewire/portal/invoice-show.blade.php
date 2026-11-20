<div>
    <x-ui.breadcrumb :items="[['Invoices', route('portal.invoices')], [$invoice->number, null]]" />

    <x-ui.page-header :title="'Invoice '.$invoice->number" :subtitle="'Issued '.$invoice->issued_on->toFormattedDateString().($invoice->due_on ? ' · pay by '.$invoice->due_on->toFormattedDateString() : '')">
        <x-slot:actions>
            <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-primary gap-1" data-test="portal-invoice-pdf"><x-icon name="file-text" /> Download PDF</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card overflow-hidden p-0" data-test="portal-invoice"><div class="overflow-x-auto">@include('invoices._body', ['invoice' => $invoice])</div></div>
</div>
