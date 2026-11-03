<div>
    <x-ui.page-header title="Payments" subtitle="Payments we have received from you." />

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments yet" message="Payments will appear here once recorded." />
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th class="table-th">Date</th><th class="table-th text-right">Amount</th><th class="table-th">Type</th><th class="table-th">Method</th>
                    <th class="table-th">Project</th>
                </tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td">{{ $payment->paid_on->format('d M Y') }}</td>
                        <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</td>
                        <td class="table-td">{{ ucwords(str_replace('_', ' ', $payment->type->value)) }}</td>
                        <td class="table-td">{{ $payment->method ?: '—' }}</td>
                        <td class="table-td">{{ $payment->project?->name ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</div>
