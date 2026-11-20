{{-- One active filter with a remove button: <x-ui.filter-chip label="Client: Acme" wire:click="$set('clientFilter', '')" />.
     Extra attributes go on the remove button. --}}
@props(['label'])
<span class="inline-flex items-center gap-1 rounded-full bg-brand-50 py-1 pl-2.5 pr-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-900/30 dark:text-brand-300 dark:ring-brand-800" data-test="filter-chip">
    {{ $label }}
    <button type="button" {{ $attributes }} class="rounded-full p-0.5 hover:bg-brand-100 dark:hover:bg-brand-800/50" aria-label="Remove filter {{ $label }}">
        <x-icon name="x" class="h-3 w-3" />
    </button>
</span>
