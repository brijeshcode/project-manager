{{-- Description card; "..." menu (Copy link / Copy Markdown / Edit) and in-place editor; an empty one shows the editor straight away.
     $text, $canEdit, $descEdit (latest description activity, may be null). --}}
<x-ui.card padding="p-0" data-test="description-card" id="description">
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
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2">
            <span class="text-xs font-semibold text-slate-500">Description
                @if ($descEdit ?? null)
                    <span class="font-normal text-slate-400" data-test="last-edited">&middot; Last edited by {{ $descEdit->user?->name ?? 'System' }} {{ $descEdit->created_at->diffForHumans() }}</span>
                @endif
            </span>
            <div class="flex items-center gap-1">
                @if ($canEdit)
                    <button type="button" wire:click="editDescription" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-brand-600" title="Edit description" aria-label="Edit description" data-test="edit-description">
                        <x-icon name="pencil" />
                    </button>
                @endif
                <x-ui.card-menu anchor="description" target="description" :edit="$canEdit ? 'editDescription' : null" />
            </div>
        </div>
        <div class="p-4">
            @if ($text)
                <x-ui.markdown :text="$text" :toggle="$canEdit ? 'description' : null" />
            @else
                <p class="text-sm text-slate-400">No description yet.</p>
            @endif
        </div>
    @endif
</x-ui.card>
