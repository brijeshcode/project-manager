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
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
            </button>
        @endif
        <h1 class="min-w-0 break-words font-display text-2xl font-bold tracking-tight text-slate-900" data-test="item-title">{{ $heading }}</h1>
    </div>
@endif
