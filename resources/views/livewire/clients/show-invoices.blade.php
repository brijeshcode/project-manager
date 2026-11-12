@php
    $invStatus = fn ($s) => [['sent' => 'Sent', 'void' => 'Cancelled', 'draft' => 'Not sent'][$s] ?? ucfirst($s), ['sent' => 'green', 'void' => 'rose', 'draft' => 'amber'][$s] ?? 'slate'];
    $deleteAsk = fn ($i) => ['method' => 'deleteInvoice', 'id' => $i->id, 'title' => 'Delete invoice', 'text' => 'Delete '.$i->number.' permanently? Its lines return to pending and the client balance changes.'];
@endphp
<div class="space-y-4" data-test="client-invoices"
     x-data="{ view: (() => { try { return localStorage.getItem('clientInvoicesView') || 'list' } catch (e) { return 'list' } })(),
               setView(v) { this.view = v; try { localStorage.setItem('clientInvoicesView', v) } catch (e) {} } }">
    <div class="flex flex-wrap items-center justify-end gap-2">
        @include('livewire.projects._view-toggle', ['live' => false])
        @can('manage-money')
            <a href="{{ route('invoices.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary" data-test="client-add-invoice"><x-nav.icon name="plus" class="h-5 w-5" /> New invoice</a>
        @endcan
    </div>
    @if ($futureInvoices->isNotEmpty())
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-900/10">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-200">Future invoices (not sent)</h3>
                <span class="text-sm font-semibold tabular-nums text-amber-900 dark:text-amber-200">Estimate: {{ \App\Support\Money::format($futureInvoices->sum('total_cents'), $client->currency) }}</span>
            </div>
            <p class="mb-3 text-xs text-amber-800 dark:text-amber-300">For your planning only. These do not change the client's balance until you send them.</p>
            <ul class="space-y-1 text-sm">
                @foreach ($futureInvoices as $f)
                    <li wire:key="future-{{ $f->id }}" class="flex items-center justify-between">
                        <a href="{{ route('invoices.show', $f) }}" wire:navigate class="link font-medium">{{ $f->number }}</a>
                        <span class="flex items-center gap-1">
                            <span class="tabular-nums">{{ \App\Support\Money::format($f->total_cents, $f->currency) }}</span>
                            @include('livewire.invoices._status', ['i' => $f, 'label' => 'Not sent', 'color' => 'amber', 'statusMethod' => 'setInvoiceStatus', 'statusKey' => '-f'])
                            <a href="{{ route('invoices.edit', $f) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $f->number }}"><x-icon name="pencil" class="h-4 w-4" /></a>
                            <button type="button" x-on:click="ask = @js(['method' => 'deleteInvoice', 'id' => $f->id, 'title' => 'Delete invoice', 'text' => 'Delete '.$f->number.' permanently? Its lines return to pending.'])" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $f->number }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($invoices->isEmpty() && $futureInvoices->isEmpty())
        <x-ui.empty-state title="No invoices yet" message="Invoices issued to this client will appear here." name="document" />
    @elseif ($invoices->isNotEmpty())
        <div x-show="view === 'list'" class="overflow-x-auto" data-test="client-invoices-list">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead><tr><th class="table-th">Invoice</th><th class="table-th">Date</th><th class="table-th">Total</th><th class="table-th">Status</th><th class="table-th"></th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($invoices as $invoice)
                        @php [$label, $color] = $invStatus($invoice->status); @endphp
                        <tr wire:key="invoice-{{ $invoice->id }}">
                            <td class="table-td"><a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="link font-medium">{{ $invoice->number }}</a></td>
                            <td class="table-td">{{ $invoice->issued_on?->format('M j, Y') }}</td>
                            <td class="table-td tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</td>
                            <td class="table-td">@include('livewire.invoices._status', ['i' => $invoice, 'statusMethod' => 'setInvoiceStatus', 'statusKey' => '-l'])</td>
                            @include('livewire.clients._row-actions', ['edit' => $invoice->status !== 'void' ? route('invoices.edit', $invoice) : null] + $deleteAsk($invoice))
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div x-show="view === 'cards'" x-cloak class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-test="client-invoices-cards">
            @foreach ($invoices as $invoice)
                @php [$label, $color] = $invStatus($invoice->status); @endphp
                <div wire:key="invoice-card-{{ $invoice->id }}" class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="truncate font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $invoice->number }}</a>
                        <div class="shrink-0">@include('livewire.invoices._status', ['i' => $invoice, 'statusMethod' => 'setInvoiceStatus', 'statusKey' => '-c'])</div>
                    </div>
                    <p class="mt-3 font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</p>
                    <dl class="mt-4 text-xs">
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                            <dt class="text-slate-500 dark:text-gray-400">Issued</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $invoice->issued_on?->format('M j, Y') }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 flex items-center justify-end border-t border-slate-100 pt-3 dark:border-gray-700">
                        <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn btn-ghost px-2" title="Download PDF" aria-label="Download PDF for {{ $invoice->number }}"><x-icon name="file-text" class="h-4 w-4" /></a>
                        @if ($invoice->status !== 'void')
                            <a href="{{ route('invoices.edit', $invoice) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $invoice->number }}"><x-icon name="pencil" class="h-4 w-4" /></a>
                        @endif
                        <button type="button" x-on:click="ask = @js($deleteAsk($invoice))" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $invoice->number }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
