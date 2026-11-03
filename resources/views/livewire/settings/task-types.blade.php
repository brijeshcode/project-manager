<div>
    <x-ui.page-header title="Task types" subtitle="Every task has one type. Drag to reorder; only the super admin can change this list.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-icon name="plus" /> New type</button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing !== null)
        @include('livewire.settings._taxonomy-form', ['noun' => 'type'])
    @endif

    <div class="card overflow-hidden p-0">
        <ul class="divide-y divide-slate-100 dark:divide-gray-700" x-data x-sortable data-sort-method="reorder" data-test="types">
            @foreach ($types as $t)
                <li wire:key="type-{{ $t->id }}" data-id="{{ $t->id }}" class="bg-white dark:bg-transparent" data-test="type-row">
                    <div class="flex items-center gap-3 px-3 py-3">
                        <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                        <span class="h-5 w-5 shrink-0 rounded" style="background: {{ $t->color }}" title="{{ $t->color }}"></span>
                        <x-ui.type-badge :type="$t" />
                        <span class="text-xs text-slate-400">{{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }}@if ($t->is_system) &middot; built-in @endif</span>
                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" wire:click="edit({{ $t->id }})" class="btn btn-ghost h-8 px-2" title="Edit {{ $t->name }}" aria-label="Edit {{ $t->name }}" data-test="edit"><x-icon name="pencil" /></button>
                            <button type="button" wire:click="confirmDelete({{ $t->id }})" class="btn btn-ghost h-8 px-2 text-rose-600" title="Delete {{ $t->name }}" aria-label="Delete {{ $t->name }}" data-test="delete"><x-icon name="trash-2" /></button>
                        </span>
                    </div>
                    @if ($deleting === $t->id)
                        <div class="border-t border-slate-100 bg-rose-50/60 px-4 py-3 text-sm dark:border-gray-700 dark:bg-rose-950/20" data-test="delete-panel">
                            @if ($t->tasks_count && $t->is_system)
                                <p>{{ $t->name }} is a built-in type and {{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }} still use it, so it cannot be deleted. You can edit it instead.</p>
                                <button type="button" wire:click="cancel" class="btn btn-secondary mt-2 h-8 text-xs">Close</button>
                            @else
                                @if ($t->tasks_count)
                                    <p>{{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }} use this type. Move them to:</p>
                                    <select wire:model="replacement" class="select mt-1 h-9 max-w-xs text-sm" data-test="replacement">
                                        <option value="">Choose a type…</option>
                                        @foreach ($types->where('id', '!=', $t->id) as $o) <option value="{{ $o->id }}">{{ $o->name }}</option> @endforeach
                                    </select>
                                @else
                                    <p>Delete {{ $t->name }}? No tasks use it.</p>
                                @endif
                                @error('replacement') <p class="field-error">{{ $message }}</p> @enderror
                                <div class="mt-2 flex gap-2">
                                    <button type="button" wire:click="delete" class="btn btn-primary h-8 text-xs" data-test="confirm-delete">Delete</button>
                                    <button type="button" wire:click="cancel" class="btn btn-secondary h-8 text-xs">Cancel</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>
