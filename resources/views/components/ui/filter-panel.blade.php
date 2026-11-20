{{-- Collapsible filter panel, closed by default, opened by an <x-ui.filter-button> with the same $for.
     Inputs inside should be deferred (wire:model, <x-ui.date-filter defer>): Apply sends them in one request and closes the panel.
     <x-ui.filter-panel for="filters">  filters…  <x-slot:bar>search box</x-slot:bar>  <x-slot:chips><x-ui.filter-chip …/></x-slot:chips>  </x-ui.filter-panel>
     Bar and chips stay visible while the panel is closed. Passing :active instead renders the button inline beside the bar. --}}
@props(['for' => 'filters', 'active' => null])
<div x-data="{ filtersOpen: false }" x-on:toggle-filters.window="if ($event.detail === '{{ $for }}') filtersOpen = ! filtersOpen" {{ $attributes->merge(['class' => 'mb-6']) }} data-test="filter-panel">
    @if ($active !== null)
        <div class="flex items-center gap-2">
            <div class="min-w-0 flex-1">@isset($bar){{ $bar }}@endisset</div>
            <x-ui.filter-button :for="$for" :active="$active" />
        </div>
    @else
        @isset($bar){{ $bar }}@endisset
    @endif
    <div x-show="filtersOpen" x-collapse style="display:none" id="{{ $for }}">
        <form wire:submit="$refresh" x-on:submit="filtersOpen = false" @class(['card space-y-4 p-4', 'mt-3' => isset($bar) || $active !== null])>
            {{ $slot }}
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-3 dark:border-gray-700">
                <button type="button" x-on:click="filtersOpen = false" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" data-test="filter-apply">Apply filters</button>
            </div>
        </form>
    </div>
    @if (isset($chips) && trim($chips) !== '')
        <div class="mt-3 flex flex-wrap items-center gap-2" data-test="filter-chips">{{ $chips }}</div>
    @endif
</div>
