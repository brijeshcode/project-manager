{{-- Title with a pencil in front; edits inline. $heading, $canEdit. Needs ItemPage on the host. --}}
@if ($editingTitle)
    <form wire:submit="saveTitle" class="flex flex-wrap items-center gap-2">
        <input type="text" wire:model="title" class="input max-w-xl flex-1 font-display text-lg font-semibold" autofocus data-test="title-input">
        <button type="submit" class="btn btn-primary h-9 px-3 py-1 text-sm">Save</button>
        <button type="button" wire:click="$set('editingTitle', false)" class="btn btn-ghost h-9 px-3 py-1 text-sm">Cancel</button>
    </form>
    @error('title') <p class="field-error">{{ $message }}</p> @enderror
@else
    <div class="flex items-start gap-2">
        @if ($canEdit)
            <button type="button" wire:click="editTitle" class="mt-1.5 shrink-0 text-slate-400 hover:text-brand-600" title="Edit title" aria-label="Edit title" data-test="edit-title">
                <x-icon name="pencil" class="h-5 w-5" />
            </button>
        @endif
        <h1 class="min-w-0 break-words font-display text-2xl font-bold tracking-tight text-slate-900" data-test="item-title">{{ $heading }}</h1>
    </div>
@endif
