{{-- Column headings for invoice item rows; widths match _custom-line and the work rows. --}}
@php $showQty ??= true; @endphp
<div class="hidden gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium uppercase tracking-wide text-slate-500 sm:flex dark:border-gray-700 dark:bg-gray-700/40 dark:text-gray-400">
    <span class="flex-1">Description</span>
    @if ($showQty)
        <span class="w-20 shrink-0 text-right">Qty</span>
        <span class="w-32 shrink-0 text-right">Unit price</span>
        <span class="w-32 shrink-0 text-right">Amount</span>
    @else
        <span class="w-36 shrink-0 text-right">Amount</span>
    @endif
    <span class="w-10 shrink-0"></span>
</div>
