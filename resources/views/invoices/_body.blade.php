@php
    $__client = $invoice->client;
    $__cur = $invoice->currency;
    $__bank = $invoice->bank_snapshot;
    $__phones = config('billing.contact_phones');
    $__lines = $invoice->lines_snapshot ?? $invoice->lines->map(fn ($l) => [
        'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price_cents' => $l->unit_price_cents, 'amount_cents' => $l->amount_cents,
    ])->all();
    // one gutter for every section so edges line up; dompdf/email need plain inline styles
    $__g = '24px';
    $__cap = 'font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;color:#64748b;margin-bottom:6px;';
    $__th = 'padding:10px 8px;font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;color:#64748b;border-bottom:1px solid #e2e8f0;';
    $__td = 'padding:12px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top;';
@endphp
{{-- Tables + inline styles (no flex/grid) so this renders in dompdf and email clients as well as the browser. --}}
<div class="bg-white text-sm text-slate-800" style="background:#fff;color:#1e293b;font-size:13px;line-height:1.5;">
    <table style="width:100%;border-collapse:collapse;background:#0f172a;color:#fff;"><tr>
        <td style="padding:{{ $__g }};vertical-align:top;">
            <div style="font-size:18px;font-weight:bold;">{{ config('billing.company_name') }}</div>
            @if (config('billing.tagline'))<div style="font-size:11px;color:#cbd5e1;">{{ config('billing.tagline') }}</div>@endif
        </td>
        <td style="padding:{{ $__g }};text-align:right;vertical-align:top;">
            <div style="font-size:22px;font-weight:bold;letter-spacing:2px;">INVOICE</div>
            <div style="color:#cbd5e1;">{{ $invoice->number }}</div>
            @if ($invoice->status === 'void')<div style="margin-top:6px;display:inline-block;border:2px solid #fca5a5;color:#fca5a5;padding:2px 10px;font-size:16px;font-weight:bold;letter-spacing:3px;">VOID</div>@endif
        </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="padding:{{ $__g }};vertical-align:top;width:55%;">
            <div style="{{ $__cap }}">Bill to</div>
            @if ($__client->company_name)
                <div style="font-weight:bold;">{{ $__client->company_name }}</div>
                <div>Attn: {{ $__client->name }}</div>
            @else
                <div style="font-weight:bold;">{{ $__client->name }}</div>
            @endif
            @if ($__client->tax_number)<div>Tax no: {{ $__client->tax_number }}</div>@endif
            @if ($__client->billing_address ?: $__client->address)<div style="white-space:pre-line;">{{ $__client->billing_address ?: $__client->address }}</div>@endif
            @if ($__client->contact_email)<div>{{ $__client->contact_email }}</div>@endif
            @if ($__client->contact_phone)<div>{{ $__client->contact_phone }}</div>@endif
        </td>
        <td style="padding:{{ $__g }};vertical-align:top;">
            <table align="right" style="border-collapse:collapse;margin-left:auto;">
                <tr><td style="padding:0 16px 4px 0;color:#64748b;">Invoice no.</td><td style="padding:0 0 4px;text-align:right;">{{ $invoice->number }}</td></tr>
                <tr><td style="padding:0 16px 4px 0;color:#64748b;">Issued</td><td style="padding:0 0 4px;text-align:right;">{{ $invoice->issued_on->toFormattedDateString() }}</td></tr>
                @if ($invoice->due_on)<tr><td style="padding:0 16px 4px 0;color:#64748b;">Due</td><td style="padding:0 0 4px;text-align:right;font-weight:bold;">{{ $invoice->due_on->toFormattedDateString() }}</td></tr>@endif
            </table>
        </td>
    </tr></table>

    <div style="padding:0 {{ $__g }};">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="{{ $__th }}padding-left:0;text-align:left;width:28px;">#</th>
                    <th style="{{ $__th }}text-align:left;">Description</th>
                    @if ($invoice->show_quantity)<th style="{{ $__th }}text-align:right;">Qty</th>
                    <th style="{{ $__th }}text-align:right;">Unit price</th>@endif
                    <th style="{{ $__th }}padding-right:0;text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($__lines as $line)
                    <tr>
                        <td style="{{ $__td }}padding-left:0;color:#64748b;">{{ $loop->iteration }}</td>
                        <td style="{{ $__td }}">{{ $line['description'] }}</td>
                        @if ($invoice->show_quantity)<td style="{{ $__td }}text-align:right;white-space:nowrap;">{{ rtrim(rtrim((string) $line['quantity'], '0'), '.') }}</td>
                        <td style="{{ $__td }}text-align:right;white-space:nowrap;">{{ \App\Support\Money::format($line['unit_price_cents'], $__cur) }}</td>@endif
                        <td style="{{ $__td }}padding-right:0;text-align:right;white-space:nowrap;">{{ \App\Support\Money::format($line['amount_cents'], $__cur) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table align="right" style="border-collapse:collapse;margin:16px 0 0 auto;min-width:260px;">
            <tr><td style="padding:4px 24px 4px 0;color:#64748b;">Subtotal</td><td style="padding:4px 0;text-align:right;white-space:nowrap;">{{ \App\Support\Money::format($invoice->subtotal_cents, $__cur) }}</td></tr>
            <tr><td style="padding:4px 24px 4px 0;color:#64748b;">Tax ({{ rtrim(rtrim((string) $invoice->tax_percent, '0'), '.') ?: '0' }}%)</td><td style="padding:4px 0;text-align:right;white-space:nowrap;">{{ \App\Support\Money::format($invoice->tax_cents, $__cur) }}</td></tr>
            <tr><td style="padding:10px 24px 0 0;border-top:2px solid #0f172a;font-size:15px;font-weight:bold;">Total due</td><td style="padding:10px 0 0;border-top:2px solid #0f172a;text-align:right;font-size:15px;font-weight:bold;white-space:nowrap;">{{ \App\Support\Money::format($invoice->total_cents, $__cur) }}</td></tr>
        </table>
        <div style="clear:both;"></div>
    </div>

    <table style="width:100%;border-collapse:collapse;margin-top:{{ $__g }};border-top:1px solid #e2e8f0;"><tr>
        <td style="padding:{{ $__g }};vertical-align:top;width:50%;">
            <div style="{{ $__cap }}">Payment terms</div>
            <div>{{ $invoice->terms ?: 'Due on receipt' }}</div>
            @if (config('billing.contact_email') || $__phones)
                <div style="{{ $__cap }}margin-top:16px;">Contact</div>
                @if (config('billing.contact_email'))<div>{{ config('billing.contact_email') }}</div>@endif
                @foreach ($__phones as $__p)<div>{{ $__p }}</div>@endforeach
            @endif
        </td>
        <td style="padding:{{ $__g }};vertical-align:top;">
            @if ($__bank)
                <div style="{{ $__cap }}">Bank details</div>
                <div>{{ $__bank['account_name'] ?? '' }}</div>
                <div>{{ $__bank['bank_name'] ?? '' }}</div>
                <div>Account: {{ $__bank['account_number'] ?? '' }}</div>
                <div>IFSC/SWIFT: {{ $__bank['ifsc_swift'] ?? '' }}</div>
                @if (! empty($__bank['email']))<div>{{ $__bank['email'] }}</div>@endif
            @endif
        </td>
    </tr></table>
</div>
