{{-- Funnel button that opens the <x-ui.filter-panel> with the same $for; sits in page-header actions beside the view toggle. --}}
@props(['for' => 'filters', 'active' => 0])
<button type="button" x-data x-on:click="$dispatch('toggle-filters', '{{ $for }}')" title="Filters" aria-label="Filters{{ $active ? ' ('.$active.' active)' : '' }}" aria-controls="{{ $for }}"
    {{ $attributes->merge(['class' => 'relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-gray-700 dark:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800']) }} data-test="filter-toggle">
    <x-icon name="funnel" class="h-4 w-4" />
    @if ($active)
        <span class="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white" data-test="filter-count">{{ $active }}</span>
    @endif
</button>
