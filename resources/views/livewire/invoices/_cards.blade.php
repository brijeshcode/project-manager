{{-- Card view of the invoices page: same data and actions as the table. --}}
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="invoice-cards">
    @foreach ($invoices as $i)
        @php [$label, $color] = match ($i->status) { 'void' => ['Cancelled', 'rose'], 'draft' => ['Not sent', 'amber'], default => ['Sent', 'brand'] }; @endphp
        <div wire:key="inv-card-{{ $i->id }}" class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800" data-test="invoice-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('invoices.show', $i) }}" class="block truncate font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $i->number }}</a>
                    <p class="truncate text-sm text-slate-500 dark:text-gray-400">{{ $i->client->name }}</p>
                </div>
                <div class="shrink-0">@include('livewire.invoices._status')</div>
            </div>
            <p class="mt-4 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ \App\Support\Money::format($i->total_cents, $i->currency) }}</p>
            @if ($i->localTotalCents() !== null && strtoupper($i->currency) !== $local)
                <p class="text-xs tabular-nums text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($i->localTotalCents(), $local) }}</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                    <dt class="text-slate-500 dark:text-gray-400">Issued</dt>
                    <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $i->issued_on->toFormattedDateString() }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                    <dt class="text-slate-500 dark:text-gray-400">Due</dt>
                    <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $i->due_on?->toFormattedDateString() ?? 'On receipt' }}</dd>
                </div>
            </dl>
            <div class="mt-4 flex items-center justify-end border-t border-slate-100 pt-3 dark:border-gray-700">@include('livewire.invoices._actions')</div>
        </div>
    @endforeach
</div>
