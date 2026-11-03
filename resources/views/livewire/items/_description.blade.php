{{-- Description card; pencil edits in place, an empty one shows the editor straight away. $text, $canEdit. --}}
<x-ui.card padding="p-0" data-test="description-card">
    @if ($editingDesc || (! $text && $canEdit))
        <div class="space-y-2 p-4" @if (! $text) data-test="empty-description" @endif>
            @unless ($text)
                <p class="text-sm font-medium text-slate-700">No description yet. Add one:</p>
            @endunless
            <x-ui.markdown-editor model="description" :rows="8" />
            @error('description') <p class="field-error">{{ $message }}</p> @enderror
            <div class="flex gap-2">
                <button type="button" wire:click="saveDescription" class="btn btn-primary h-8 px-3 py-1 text-xs" data-test="save-description">Save</button>
                @if ($text) <button type="button" wire:click="cancelDescription" class="btn btn-ghost h-8 px-3 py-1 text-xs">Cancel</button> @endif
            </div>
        </div>
    @else
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2">
            <span class="text-xs font-semibold text-slate-500">Description</span>
            @if ($canEdit)
                <button type="button" wire:click="editDescription" class="text-slate-400 hover:text-brand-600" title="Edit description" aria-label="Edit description" data-test="edit-description">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                </button>
            @endif
        </div>
        <div class="p-4">
            @if ($text)
                <x-ui.markdown :text="$text" />
            @else
                <p class="text-sm text-slate-400">No description yet.</p>
            @endif
        </div>
    @endif
</x-ui.card>
