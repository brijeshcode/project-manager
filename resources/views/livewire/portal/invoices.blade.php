<div>
    <x-ui.page-header title="Invoices" subtitle="The invoices we have sent you.">
        <x-slot:actions>@include('livewire.projects._view-toggle', ['live' => true])</x-slot:actions>
    </x-ui.page-header>

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No invoices yet" message="Invoices will appear here once they are issued." />
    @elseif ($view === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="portal-invoices-cards">
            @foreach ($invoices as $invoice)
                <div wire:key="pi-{{ $invoice->id }}" class="card flex flex-col p-5" data-test="portal-invoice-card">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="truncate font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $invoice->number }}</a>
                        <x-icon name="file-text" class="h-5 w-5 text-slate-400" />
                    </div>
                    <p class="mt-3 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div><dt class="text-slate-500 dark:text-gray-400">Issued</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $invoice->issued_on->format('d M Y') }}</dd></div>
                        <div><dt class="text-slate-500 dark:text-gray-400">Pay by</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $invoice->due_on?->format('d M Y') ?? '—' }}</dd></div>
                    </dl>
                    <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-sm dark:border-gray-700">
                        <a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="link" data-test="portal-invoice-view">View details</a>
                        <a href="{{ route('invoices.pdf', $invoice) }}" class="link">Download PDF</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @else
        <div class="card overflow-x-auto" data-test="portal-invoices-list">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th class="table-th">Number</th><th class="table-th">Issued</th><th class="table-th">Pay by</th>
                    <th class="table-th text-right">Total</th><th class="table-th"></th>
                </tr></thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    <tr wire:key="pil-{{ $invoice->id }}" class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td font-medium"><a href="{{ route('portal.invoice', $invoice) }}" wire:navigate class="link">{{ $invoice->number }}</a></td>
                        <td class="table-td">{{ $invoice->issued_on->format('d M Y') }}</td>
                        <td class="table-td">{{ $invoice->due_on?->format('d M Y') }}</td>
                        <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</td>
                        <td class="table-td whitespace-nowrap text-right"><a class="link mr-3" href="{{ route('portal.invoice', $invoice) }}" wire:navigate data-test="portal-invoice-view">View details</a><a class="link" href="{{ route('invoices.pdf', $invoice) }}">Download PDF</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
