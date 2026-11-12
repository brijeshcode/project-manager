{{-- Cards / List switch. $live: drives the Livewire $view (URL); otherwise an Alpine `view` in a parent x-data. --}}
<div class="inline-flex shrink-0 overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700" role="group" aria-label="View" data-test="view-toggle">
    @foreach (['cards' => ['Cards', 'layout-dashboard'], 'list' => ['List', 'list-filter']] as $key => [$label, $icon])
        @if ($live)
            <button type="button" wire:click="$set('view', '{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" title="{{ $label }} view" aria-label="{{ $label }} view" data-test="view-{{ $key }}"
                @class(['flex h-9 w-9 items-center justify-center', 'bg-brand-600 text-white' => $view === $key, 'bg-white text-slate-500 hover:bg-slate-50 dark:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800' => $view !== $key])><x-icon :name="$icon" class="h-4 w-4" /></button>
        @else
            <button type="button" x-on:click="setView('{{ $key }}')" :aria-pressed="view === '{{ $key }}'" title="{{ $label }} view" aria-label="{{ $label }} view" data-test="view-{{ $key }}"
                :class="view === '{{ $key }}' ? 'bg-brand-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50 dark:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800'"
                class="flex h-9 w-9 items-center justify-center"><x-icon :name="$icon" class="h-4 w-4" /></button>
        @endif
    @endforeach
</div>
