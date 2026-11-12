<div x-data="{ ask: null }">
    <x-ui.page-header title="Labels" subtitle="Everyone can attach these to modules, tasks and subtasks; only the super admin can change the list.">
        <x-slot:actions>
            <a href="{{ route('settings.task-types') }}" wire:navigate class="btn btn-ghost">Task types</a>
            @if ($editing === null)
                <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-icon name="plus" /> New label</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing !== null)
        @include('livewire.settings._taxonomy-form', [
            'noun' => 'label', 'max' => 50, 'placeholder' => 'e.g. Needs review',
            'current' => $editing ? $labels->firstWhere('id', $editing) : null,
            'hint' => 'Renaming or recolouring updates the label everywhere it is attached.',
        ])
    @endif

    @if ($labels->isEmpty())
        @if ($editing === null)
            <x-ui.empty-state name="tag" title="No labels yet" message="Create labels so people can tag modules, tasks and subtasks.">
                <x-slot:action>
                    <button type="button" wire:click="create" class="btn btn-primary">New label</button>
                </x-slot:action>
            </x-ui.empty-state>
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th">Label</th><th class="table-th">Colour</th><th class="table-th">Used on</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700" data-test="labels">
                    @foreach ($labels as $l)
                        <tr wire:key="label-{{ $l->id }}" @class(['bg-brand-50/50 dark:bg-gray-700/30' => $editing === $l->id]) data-test="label-row">
                            <td class="table-td"><x-ui.label-chip :label="$l" /></td>
                            <td class="table-td whitespace-nowrap">
                                <span class="inline-flex items-center gap-2"><span class="h-4 w-4 shrink-0 rounded" style="background: {{ $l->color }}"></span><span class="font-mono text-xs text-slate-500 dark:text-gray-400">{{ $l->color }}</span></span>
                            </td>
                            <td class="table-td whitespace-nowrap">{{ $l->uses }} {{ Str::plural('item', $l->uses) }}</td>
                            <td class="table-td whitespace-nowrap text-right">
                                <button type="button" wire:click="edit({{ $l->id }})" class="text-sm font-medium link" aria-label="Edit {{ $l->name }}" data-test="edit">Edit</button>
                                <button type="button" x-on:click="ask = { method: 'delete', id: {{ $l->id }}, title: @js('Delete '.$l->name.'?'), text: @js($l->uses ? 'It is removed from the '.$l->uses.' '.Str::plural('item', $l->uses).' that use it. This cannot be undone.' : 'Nothing uses it. This cannot be undone.') }" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" aria-label="Delete {{ $l->name }}" data-test="delete">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.confirm-modal />
</div>
