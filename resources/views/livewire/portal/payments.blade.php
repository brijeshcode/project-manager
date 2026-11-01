<div>
    <x-ui.page-header title="Payments" subtitle="Payments we have received from you." />

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments yet" message="Payments will appear here once recorded." />
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500">
                    <th class="p-3">Date</th><th class="p-3 text-right">Amount</th><th class="p-3">Type</th>
                    <th class="p-3">Invoice</th><th class="p-3">Project</th>
                </tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr class="border-t border-slate-100">
                        <td class="p-3">{{ $payment->paid_on->format('d M Y') }}</td>
                        <td class="p-3 text-right tabular-nums">{{ \App\Support\Money::format($payment->amount_cents, $currency) }}</td>
                        <td class="p-3">{{ ucwords(str_replace('_', ' ', $payment->type->value)) }}</td>
                        <td class="p-3">{{ $payment->invoice?->number ?? '—' }}</td>
                        <td class="p-3">{{ $payment->project?->name ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</div>
