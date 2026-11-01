@php
    $__client = $invoice->client;
    $__cur = $invoice->currency;
    $__bank = $invoice->bank_snapshot;
    $__phones = config('billing.contact_phones');
    $__lines = $invoice->lines_snapshot ?? $invoice->lines->map(fn ($l) => [
        'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => $l->unit_price_cents, 'amount_cents' => $l->amount_cents,
    ])->all();
@endphp
{{-- Tables + inline styles (no flex/grid) so this renders in dompdf and email clients as well as the browser. --}}
<div class="bg-white text-sm text-slate-800" style="background:#fff;color:#1e293b;font-size:13px;">
    <table style="width:100%;border-collapse:collapse;background:#0f172a;color:#fff;"><tr>
        <td style="padding:18px 24px;vertical-align:top;">
            <div style="font-size:18px;font-weight:bold;">{{ config('billing.company_name') }}</div>
            @if (config('billing.tagline'))<div style="font-size:11px;color:#cbd5e1;">{{ config('billing.tagline') }}</div>@endif
        </td>
        <td style="padding:18px 24px;text-align:right;vertical-align:top;">
            <div style="font-size:22px;font-weight:bold;letter-spacing:2px;">INVOICE</div>
            <div>{{ $invoice->number }}</div>
            @if ($invoice->status === 'void')<div style="margin-top:6px;display:inline-block;border:2px solid #fca5a5;color:#fca5a5;padding:2px 10px;font-size:16px;font-weight:bold;letter-spacing:3px;">VOID</div>@endif
        </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="padding:18px 24px;vertical-align:top;width:50%;">
            <div style="background:#f8fafc;padding:12px;">
                <div style="font-size:11px;font-weight:bold;text-transform:uppercase;color:#64748b;">Bill to</div>
                <div style="font-weight:bold;">{{ $__client->name }}</div>
                @if ($__client->tax_number)<div>Tax no: {{ $__client->tax_number }}</div>@endif
                @if ($__client->billing_address ?: $__client->address)<div style="white-space:pre-line;">{{ $__client->billing_address ?: $__client->address }}</div>@endif
                @if ($__client->contact_email)<div>{{ $__client->contact_email }}</div>@endif
                @if ($__client->contact_phone)<div>{{ $__client->contact_phone }}</div>@endif
            </div>
        </td>
        <td style="padding:18px 24px;vertical-align:top;text-align:right;">
            <div><span style="color:#64748b;">Issued:</span> {{ $invoice->issued_on->toFormattedDateString() }}</div>
            @if ($invoice->due_on)<div><span style="color:#64748b;">Due:</span> {{ $invoice->due_on->toFormattedDateString() }}</div>@endif
        </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="background:#f1f5f9;color:#64748b;font-size:11px;text-transform:uppercase;"><th style="padding:8px 12px;text-align:left;">#</th><th style="padding:8px 12px;text-align:left;">Description</th><th style="padding:8px 12px;text-align:right;">Qty</th><th style="padding:8px 12px;text-align:right;">Unit price</th><th style="padding:8px 12px;text-align:right;">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($__lines as $line)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px 12px;">{{ $loop->iteration }}</td>
                    <td style="padding:8px 12px;">{{ $line['description'] }}</td>
                    <td style="padding:8px 12px;text-align:right;">{{ rtrim(rtrim((string) $line['quantity'], '0'), '.') }}</td>
                    <td style="padding:8px 12px;text-align:right;">{{ \App\Support\Money::format($line['unit_price_cents'], $__cur) }}</td>
                    <td style="padding:8px 12px;text-align:right;">{{ \App\Support\Money::format($line['amount_cents'], $__cur) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="padding:16px 24px;text-align:right;">
        <div>Subtotal: {{ \App\Support\Money::format($invoice->subtotal_cents, $__cur) }}</div>
        <div>Tax ({{ rtrim(rtrim((string) $invoice->tax_percent, '0'), '.') ?: '0' }}%): {{ \App\Support\Money::format($invoice->tax_cents, $__cur) }}</div>
        <div style="font-size:16px;font-weight:bold;">Total: {{ \App\Support\Money::format($invoice->total_cents, $__cur) }}</div>
    </div>

    <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="padding:16px 24px;vertical-align:top;width:50%;">
            <div style="font-size:11px;font-weight:bold;text-transform:uppercase;color:#64748b;">Payment terms</div>
            <div>{{ $invoice->terms ?: 'Due on receipt' }}</div>
            @if (config('billing.contact_email') || $__phones)
                <div style="margin-top:10px;font-size:11px;font-weight:bold;text-transform:uppercase;color:#64748b;">Contact</div>
                @if (config('billing.contact_email'))<div>{{ config('billing.contact_email') }}</div>@endif
                @foreach ($__phones as $__p)<div>{{ $__p }}</div>@endforeach
            @endif
        </td>
        <td style="padding:16px 24px;vertical-align:top;">
            @if ($__bank)
                <div style="font-size:11px;font-weight:bold;text-transform:uppercase;color:#64748b;">Bank details</div>
                <div>{{ $__bank['account_name'] ?? '' }}</div>
                <div>{{ $__bank['bank_name'] ?? '' }}</div>
                <div>Account: {{ $__bank['account_number'] ?? '' }}</div>
                <div>IFSC/SWIFT: {{ $__bank['ifsc_swift'] ?? '' }}</div>
                @if (! empty($__bank['email']))<div>{{ $__bank['email'] }}</div>@endif
            @endif
        </td>
    </tr></table>
</div>
