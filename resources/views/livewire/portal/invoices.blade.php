<div>
    <x-ui.page-header title="Invoices" subtitle="Your invoices and their status." />

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No invoices yet" message="Invoices will appear here once they are issued." />
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th class="table-th">Number</th><th class="table-th">Issued</th><th class="table-th">Pay by</th>
                    <th class="table-th text-right">Total</th><th class="table-th">Status</th><th class="table-th"></th>
                </tr></thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    @php $paid = $invoice->total_cents > 0 && (int) $invoice->payments_sum_amount_cents >= $invoice->total_cents; @endphp
                    <tr class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td font-medium">{{ $invoice->number }}</td>
                        <td class="table-td">{{ $invoice->issued_on->format('d M Y') }}</td>
                        <td class="table-td">{{ $invoice->due_on?->format('d M Y') }}</td>
                        <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</td>
                        <td class="table-td"><x-ui.badge :color="$paid ? 'green' : 'slate'">{{ $paid ? 'Paid' : 'Due' }}</x-ui.badge></td>
                        <td class="table-td"><a class="link" href="{{ route('invoices.pdf', $invoice) }}">Download PDF</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
