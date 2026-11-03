<x-ui.card padding="p-0" data-test="checklist">
    @php($done = $items->where('is_done', true)->count())
    <div class="border-b border-slate-100 px-5 py-3">
        <h2 class="font-display text-sm font-semibold text-slate-900">Checklist <span class="font-normal text-slate-400" data-test="checklist-count">{{ $done }}/{{ $items->count() }}</span></h2>
        @if ($items->count())
            <x-ui.progress :pct="$done / $items->count() * 100" :label="false" class="mt-2 max-w-xs" />
        @endif
    </div>
    <ul class="divide-y divide-slate-100" x-data x-sortable data-sort-method="reorderItems" data-test="checklist-items">
        @forelse ($items as $item)
            <li wire:key="item-{{ $item->id }}" data-id="{{ $item->id }}" class="group flex items-center gap-2 bg-white px-3 py-2 dark:bg-transparent">
                @if ($canEdit)
                    <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                @endif
                <input type="checkbox" @checked($item->is_done) @disabled(! $canEdit) wire:click="toggleItem({{ $item->id }})" class="rounded border-slate-300 text-brand-600" aria-label="Done">
                @if ($editingId === $item->id)
                    <form wire:submit="saveRename" class="flex min-w-0 flex-1 gap-2">
                        <input type="text" wire:model="editTitle" class="input h-8 flex-1 text-sm" autofocus wire:keydown.escape="$set('editingId', null)">
                        <button type="submit" class="text-emerald-600" title="Save" aria-label="Save"><x-icon name="check" class="h-4 w-4" /></button>
                        <button type="button" wire:click="$set('editingId', null)" class="text-slate-400" title="Cancel" aria-label="Cancel"><x-icon name="x" class="h-4 w-4" /></button>
                    </form>
                @else
                    <span @class(['min-w-0 flex-1 truncate text-sm', 'text-slate-800' => ! $item->is_done, 'text-slate-400 line-through' => $item->is_done])>{{ $item->title }}</span>
                    @if ($canEdit)
                        <span class="flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                            <button type="button" wire:click="startRename({{ $item->id }})" class="text-slate-400 hover:text-brand-600" title="Rename item" aria-label="Rename item" data-test="rename-item"><x-icon name="pencil" /></button>
                            <button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="Delete this checklist item?" class="text-slate-400 hover:text-rose-600" title="Delete item" aria-label="Delete item" data-test="delete-item"><x-icon name="trash-2" /></button>
                        </span>
                    @endif
                @endif
            </li>
        @empty
            <li class="px-5 py-3 text-sm text-slate-400">No checklist items yet.</li>
        @endforelse
    </ul>
    @if ($canEdit)
        <form wire:submit="addItem" class="flex gap-2 border-t border-slate-100 px-5 py-3">
            <input type="text" wire:model="newTitle" placeholder="Add a checklist item…" class="input h-9 flex-1 text-sm" data-test="new-item">
            <button type="submit" class="btn btn-secondary h-9 gap-1 whitespace-nowrap text-sm"><x-icon name="plus" /> Add</button>
        </form>
        @error('newTitle') <p class="field-error px-5 pb-3">{{ $message }}</p> @enderror
        @error('editTitle') <p class="field-error px-5 pb-3">{{ $message }}</p> @enderror
    @endif
</x-ui.card>
