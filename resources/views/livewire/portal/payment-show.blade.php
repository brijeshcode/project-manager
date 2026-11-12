<div>
    <div class="mb-6">
        <a href="{{ route('portal.payments') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">&larr; Payments</a>
    </div>

    <x-ui.page-header :title="'Payment #'.$payment->id" subtitle="A payment we have received from you." />

    @php
        $fields = [
            'Date' => $payment->paid_on->format('d M Y'),
            'Amount' => \App\Support\Money::format($payment->amount_cents, $payment->currency),
            'Type' => ucwords(str_replace('_', ' ', $payment->type->value)),
            'Method' => $payment->method ? ucwords(str_replace('_', ' ', $payment->method)) : '—',
            'Project' => $payment->project?->name ?? '—',
        ];
    @endphp
    <div class="card p-6" data-test="portal-payment">
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($fields as $label => $value)
                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-4 dark:border-gray-700 dark:bg-gray-800/40">
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-1 text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($documents->isNotEmpty())
            <h2 class="mb-2 mt-6 text-sm font-semibold text-slate-900 dark:text-white">Documents</h2>
            <ul class="space-y-1">
                @foreach ($documents as $d)
                    <li wire:key="pdoc-{{ $d->id }}"><button type="button" wire:click="download({{ $d->id }})" class="flex items-center gap-1 text-left text-sm link"><x-icon name="file-text" class="h-4 w-4" />{{ $d->original_name }}</button></li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
