{{-- Funnel button that opens the <x-ui.filter-panel> with the same $for; sits in page-header actions beside the view toggle.
     Tinted with a filled funnel while any filter is active. --}}
@props(['for' => 'filters', 'active' => 0])
<button type="button" x-data x-on:click="$dispatch('toggle-filters', '{{ $for }}')" title="Filters" aria-label="Filters{{ $active ? ' ('.$active.' active)' : '' }}" aria-controls="{{ $for }}"
    {{ $attributes->class([
        'group relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border shadow-sm transition',
        'border-brand-300 bg-brand-50 text-brand-600 hover:bg-brand-100 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300' => $active,
        'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-brand-300' => ! $active,
    ]) }} data-test="filter-toggle">
    <x-icon name="funnel" :class="'h-[18px] w-[18px] transition-transform group-hover:scale-110'.($active ? ' fill-brand-600/25 dark:fill-brand-300/25' : '')" />
    @if ($active)
        <span class="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white ring-2 ring-white dark:ring-gray-900" data-test="filter-count">{{ $active }}</span>
    @endif
</button>
