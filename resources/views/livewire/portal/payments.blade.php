<div>
    <x-ui.page-header title="Payments" subtitle="Payments we have received from you.">
        <x-slot:actions>@include('livewire.projects._view-toggle', ['live' => true])</x-slot:actions>
    </x-ui.page-header>

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments yet" message="Payments will appear here once recorded." />
    @elseif ($view === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="portal-payments-cards">
            @foreach ($payments as $payment)
                <div wire:key="pp-{{ $payment->id }}" class="card flex flex-col p-5" data-test="portal-payment-card">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('portal.payment', $payment) }}" wire:navigate class="font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white" data-test="portal-payment-view">{{ $payment->paid_on->format('d M Y') }}</a>
                        <x-ui.badge>{{ ucwords(str_replace('_', ' ', $payment->type->value)) }}</x-ui.badge>
                    </div>
                    <p class="mt-3 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-slate-500 dark:text-gray-400">Method</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $payment->method ?: '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-slate-500 dark:text-gray-400">Project</dt><dd class="mt-0.5 truncate font-semibold text-slate-900 dark:text-white">{{ $payment->project?->name ?? '—' }}</dd></div>
                    </dl>
                    @if ($payment->documents->isNotEmpty())
                        <div class="mt-3 space-y-1">
                            @foreach ($payment->documents as $d)
                                <button type="button" wire:click="download({{ $d->id }})" wire:key="pcdoc-{{ $d->id }}" class="flex max-w-full items-center gap-1 text-left text-sm link" title="Download {{ $d->original_name }}"><x-icon name="file-text" class="h-4 w-4" /><span class="truncate">{{ $d->original_name }}</span></button>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-gray-700">
                        <a href="{{ route('portal.payment', $payment) }}" wire:navigate class="link">View details</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @else
        <div class="card overflow-x-auto" data-test="portal-payments-list">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th class="table-th">Date</th><th class="table-th text-right">Amount</th><th class="table-th">Type</th><th class="table-th">Method</th>
                    <th class="table-th">Project</th>
                    @if ($hasDocs)<th class="table-th">Documents</th>@endif
                    <th class="table-th"></th>
                </tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr wire:key="ppl-{{ $payment->id }}" class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td"><a href="{{ route('portal.payment', $payment) }}" wire:navigate class="link" data-test="portal-payment-view">{{ $payment->paid_on->format('d M Y') }}</a></td>
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
                        <td class="table-td text-right"><a href="{{ route('portal.payment', $payment) }}" wire:navigate class="link whitespace-nowrap">View details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</div>
