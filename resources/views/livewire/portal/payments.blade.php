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
                    @if ($hasDocs)<th class="table-th">Documents</th>@endif
                </tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td">{{ $payment->paid_on->format('d M Y') }}</td>
                        <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</td>
                        <td class="table-td">{{ ucwords(str_replace('_', ' ', $payment->type->value)) }}</td>
                        <td class="table-td">{{ $payment->method ?: '—' }}</td>
                        <td class="table-td">{{ $payment->project?->name ?? '—' }}</td>
                        @if ($hasDocs)
                            <td class="table-td">
                                @foreach ($payment->documents as $d)
                                    <button type="button" wire:click="download({{ $d->id }})" wire:key="pdoc-{{ $d->id }}" class="flex items-center gap-1 text-left text-sm link" title="Download {{ $d->original_name }}"><x-icon name="file-text" class="h-4 w-4" />{{ $d->original_name }}</button>
                                @endforeach
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</div>
