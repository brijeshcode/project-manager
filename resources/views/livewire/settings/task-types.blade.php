<div x-data="{ ask: null }">
    <x-ui.page-header title="Task types" subtitle="Every task has one type. Drag rows to reorder; only the super admin can change this list.">
        <x-slot:actions>
            <a href="{{ route('settings.labels') }}" wire:navigate class="btn btn-ghost">Labels</a>
            @if ($editing === null)
                <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-icon name="plus" /> New type</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing !== null)
        @include('livewire.settings._taxonomy-form', [
            'noun' => 'type', 'max' => 40, 'placeholder' => 'e.g. Spike',
            'current' => $editing ? $types->firstWhere('id', $editing) : null,
            'hint' => 'The colour and icon show on the type badge on tasks and the board.',
        ])
    @endif

    @if ($deleting && ($t = $types->firstWhere('id', $deleting)))
        <div class="card mb-6 max-w-3xl border-rose-200 p-6 dark:border-rose-900/50" data-test="delete-panel">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Delete {{ $t->name }}</h2>
            @if ($t->tasks_count && $t->is_system)
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">{{ $t->name }} is a built-in type and {{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }} still use it, so it cannot be deleted. You can edit it instead.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" wire:click="edit({{ $t->id }})" class="btn btn-primary">Edit {{ $t->name }}</button>
                    <button type="button" wire:click="cancel" class="btn btn-secondary">Close</button>
                </div>
            @else
                @if ($t->tasks_count)
                    <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">{{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }} use this type. Choose the type they move to; this cannot be undone.</p>
                    <div class="mt-5 max-w-xs">
                        <label for="replacement" class="label">Move tasks to</label>
                        <select id="replacement" wire:model="replacement" class="select" data-test="replacement">
                            <option value="">Choose a type…</option>
                            @foreach ($types->where('id', '!=', $t->id) as $o) <option value="{{ $o->id }}">{{ $o->name }}</option> @endforeach
                        </select>
                    </div>
                @else
                    <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">No tasks use this type.</p>
                @endif
                @error('replacement') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" wire:click="delete" class="btn btn-danger" wire:loading.attr="disabled" wire:target="delete" data-test="confirm-delete">Delete type</button>
                    <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
                </div>
            @endif
        </div>
    @endif

    @if ($types->isEmpty())
        @if ($editing === null)
            <x-ui.empty-state name="tag" title="No task types yet" message="Add at least one type; every task needs one.">
                <x-slot:action>
                    <button type="button" wire:click="create" class="btn btn-primary">New type</button>
                </x-slot:action>
            </x-ui.empty-state>
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th w-10"><span class="sr-only">Order</span></th><th class="table-th">Type</th><th class="table-th">Colour</th><th class="table-th">Tasks</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700" x-data x-sortable data-sort-method="reorder" data-test="types">
                    @foreach ($types as $t)
                        <tr wire:key="type-{{ $t->id }}" data-id="{{ $t->id }}" @class(in_array($t->id, [$editing, $deleting], true) ? 'bg-brand-50/50 dark:bg-gray-700/30' : 'bg-white dark:bg-transparent') data-test="type-row">
                            <td class="table-td w-10">
                                <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500 dark:text-gray-600 dark:hover:text-gray-400" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                            </td>
                            <td class="table-td">
                                <x-ui.type-badge :type="$t" />
                                @if ($t->is_system) <span class="ml-2 text-xs text-slate-500 dark:text-gray-400">Built-in</span> @endif
                            </td>
                            <td class="table-td whitespace-nowrap">
                                <span class="inline-flex items-center gap-2"><span class="h-4 w-4 shrink-0 rounded" style="background: {{ $t->color }}"></span><span class="font-mono text-xs text-slate-500 dark:text-gray-400">{{ $t->color }}</span></span>
                            </td>
                            <td class="table-td whitespace-nowrap">{{ $t->tasks_count }} {{ Str::plural('task', $t->tasks_count) }}</td>
                            <td class="table-td whitespace-nowrap text-right">
                                <button type="button" wire:click="edit({{ $t->id }})" class="text-sm font-medium link" aria-label="Edit {{ $t->name }}" data-test="edit">Edit</button>
                                @if ($t->tasks_count)
                                    <button type="button" wire:click="confirmDelete({{ $t->id }})" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" aria-label="Delete {{ $t->name }}" data-test="delete">Delete</button>
                                @else
                                    <button type="button" x-on:click="ask = { method: 'delete', id: {{ $t->id }}, title: @js('Delete '.$t->name.'?'), text: 'No tasks use this type. This cannot be undone.' }" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" aria-label="Delete {{ $t->name }}" data-test="delete">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.confirm-modal />
</div>
