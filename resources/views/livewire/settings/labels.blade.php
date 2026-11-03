<div>
    <x-ui.page-header title="Labels" subtitle="Everyone can attach these to modules, tasks and subtasks; only the super admin can change the list.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-icon name="plus" /> New label</button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing !== null)
        @include('livewire.settings._taxonomy-form', ['noun' => 'label'])
    @endif

    <div class="card overflow-hidden p-0">
        <ul class="divide-y divide-slate-100 dark:divide-gray-700" data-test="labels">
            @forelse ($labels as $l)
                <li wire:key="label-{{ $l->id }}" class="bg-white dark:bg-transparent" data-test="label-row">
                    <div class="flex items-center gap-3 px-3 py-3">
                        <span class="h-5 w-5 shrink-0 rounded" style="background: {{ $l->color }}" title="{{ $l->color }}"></span>
                        <x-ui.label-chip :label="$l" />
                        <span class="text-xs text-slate-400">used {{ $l->uses }} {{ Str::plural('time', $l->uses) }}</span>
                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" wire:click="edit({{ $l->id }})" class="btn btn-ghost h-8 px-2" title="Edit {{ $l->name }}" aria-label="Edit {{ $l->name }}" data-test="edit"><x-icon name="pencil" /></button>
                            <button type="button" wire:click="confirmDelete({{ $l->id }})" class="btn btn-ghost h-8 px-2 text-rose-600" title="Delete {{ $l->name }}" aria-label="Delete {{ $l->name }}" data-test="delete"><x-icon name="trash-2" /></button>
                        </span>
                    </div>
                    @if ($deleting === $l->id)
                        <div class="border-t border-slate-100 bg-rose-50/60 px-4 py-3 text-sm dark:border-gray-700 dark:bg-rose-950/20" data-test="delete-panel">
                            <p>Delete {{ $l->name }}? It is removed from the {{ $l->uses }} {{ Str::plural('item', $l->uses) }} that use it.</p>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="delete" class="btn btn-primary h-8 text-xs" data-test="confirm-delete">Delete</button>
                                <button type="button" wire:click="cancel" class="btn btn-secondary h-8 text-xs">Cancel</button>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-slate-400">No labels yet.</li>
            @endforelse
        </ul>
    </div>
</div>
