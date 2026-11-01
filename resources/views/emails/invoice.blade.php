<div style="font-family:Arial,sans-serif;color:#1e293b;">
    <p>Hello {{ $invoice->client->name }},</p>
    <p>Please find invoice {{ $invoice->number }} from {{ config('billing.company_name') }} below. A PDF copy is attached.</p>
    @include('invoices._body', ['invoice' => $invoice])
</div>
