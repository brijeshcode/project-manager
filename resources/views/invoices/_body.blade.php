@php
    $__client = $invoice->client;
    $__cur = $invoice->currency;
    $__bank = $invoice->bank_snapshot;
    // INR approx: whole rupees = round_half_up(total_cents * rate / 100); rate scaled to 4 decimals as integer, so
    // inr = intdiv(total_cents * rateScaled + 500000, 1000000). Integer math only.
    $__inr = null;
    if ($invoice->inr_rate !== null) {
        [$__ri, $__rf] = array_pad(explode('.', (string) $invoice->inr_rate), 2, '0');
        $__rate = (int) $__ri * 10000 + (int) str_pad(substr($__rf, 0, 4), 4, '0');
        // skip rather than overflow (InvoiceService bounds make this unreachable)
        if ((float) $invoice->total_cents * $__rate < 9.0e18) {
            $__inr = intdiv($invoice->total_cents * $__rate + 500000, 1000000);
        }
    }
    $__phones = config('billing.contact_phones');
@endphp
<div class="bg-white text-sm text-slate-800">
    <div class="flex items-start justify-between bg-slate-900 px-6 py-5 text-white">
        <div>
            <div class="text-lg font-bold">{{ config('billing.company_name') }}</div>
            @if (config('billing.tagline'))<div class="text-xs text-slate-300">{{ config('billing.tagline') }}</div>@endif
        </div>
        <div class="text-right">
            <div class="text-2xl font-bold tracking-widest">INVOICE</div>
            <div>{{ $invoice->number }}</div>
        </div>
    </div>

    <div class="grid gap-6 px-6 py-5 sm:grid-cols-2">
        <div class="rounded-md bg-slate-50 p-4">
            <div class="text-xs font-semibold uppercase text-slate-500">Bill to</div>
            <div class="mt-1 font-semibold">{{ $__client->name }}</div>
            @if ($__client->tax_number)<div>Tax no: {{ $__client->tax_number }}</div>@endif
            @if ($__client->billing_address ?: $__client->address)<div class="whitespace-pre-line">{{ $__client->billing_address ?: $__client->address }}</div>@endif
            @if ($__client->contact_email)<div>{{ $__client->contact_email }}</div>@endif
            @if ($__client->contact_phone)<div>{{ $__client->contact_phone }}</div>@endif
        </div>
        <div class="sm:text-right">
            <div><span class="text-slate-500">Issued:</span> {{ $invoice->issued_on->toFormattedDateString() }}</div>
            @if ($invoice->due_on)<div><span class="text-slate-500">Due:</span> {{ $invoice->due_on->toFormattedDateString() }}</div>@endif
        </div>
    </div>

    <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2 text-right">Unit price</th><th class="px-4 py-2 text-right">Amount</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="px-4 py-2">{{ $loop->iteration }}</td>
                    <td class="px-4 py-2">{{ $line->description }}</td>
                    <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    <td class="px-4 py-2 text-right">{{ \App\Support\Money::format($line->unit_price_cents, $__cur) }}</td>
                    <td class="px-4 py-2 text-right">{{ \App\Support\Money::format($line->amount_cents, $__cur) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="px-6 py-4 text-right">
        <div>Subtotal: {{ \App\Support\Money::format($invoice->subtotal_cents, $__cur) }}</div>
        <div>Tax ({{ rtrim(rtrim((string) $invoice->tax_percent, '0'), '.') ?: '0' }}%): {{ \App\Support\Money::format($invoice->tax_cents, $__cur) }}</div>
        <div class="text-lg font-bold">Total: {{ \App\Support\Money::format($invoice->total_cents, $__cur) }}</div>
        @if ($__inr !== null)<div class="text-slate-500">Approx. INR {{ number_format($__inr) }} @ {{ $invoice->inr_rate }}</div>@endif
    </div>

    <div class="grid gap-6 px-6 py-4 sm:grid-cols-2">
        <div>
            <div class="text-xs font-semibold uppercase text-slate-500">Payment terms</div>
            <div>{{ $invoice->terms ?: 'Due on receipt' }}</div>
            @if (config('billing.contact_email') || $__phones)
                <div class="mt-3 text-xs font-semibold uppercase text-slate-500">Contact</div>
                @if (config('billing.contact_email'))<div>{{ config('billing.contact_email') }}</div>@endif
                @foreach ($__phones as $__p)<div>{{ $__p }}</div>@endforeach
            @endif
        </div>
        @if ($__bank)
            <div>
                <div class="text-xs font-semibold uppercase text-slate-500">Bank details</div>
                <div>{{ $__bank['account_name'] ?? '' }}</div>
                <div>{{ $__bank['bank_name'] ?? '' }}</div>
                <div>Account: {{ $__bank['account_number'] ?? '' }}</div>
                <div>IFSC/SWIFT: {{ $__bank['ifsc_swift'] ?? '' }}</div>
                @if (! empty($__bank['email']))<div>{{ $__bank['email'] }}</div>@endif
            </div>
        @endif
    </div>
</div>
