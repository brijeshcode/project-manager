<div>
    <x-ui.page-header title="Invoices" subtitle="Your invoices and their status." />

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No invoices yet" message="Invoices will appear here once they are issued." />
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500">
                    <th class="p-3">Number</th><th class="p-3">Issued</th><th class="p-3">Pay by</th>
                    <th class="p-3 text-right">Total</th><th class="p-3">Status</th><th class="p-3"></th>
                </tr></thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    @php $paid = $invoice->total_cents > 0 && (int) $invoice->payments_sum_amount_cents >= $invoice->total_cents; @endphp
                    <tr class="border-t border-slate-100">
                        <td class="p-3 font-medium">{{ $invoice->number }}</td>
                        <td class="p-3">{{ $invoice->issued_on->format('d M Y') }}</td>
                        <td class="p-3">{{ $invoice->due_on?->format('d M Y') }}</td>
                        <td class="p-3 text-right tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</td>
                        <td class="p-3"><x-ui.badge :color="$paid ? 'green' : 'slate'">{{ $paid ? 'Paid' : 'Due' }}</x-ui.badge></td>
                        <td class="p-3"><a class="text-brand-700 hover:underline" href="{{ route('invoices.pdf', $invoice) }}">Download PDF</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
