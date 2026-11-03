<div>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', $project) }}" wire:navigate
               class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
                &larr; Workspace
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-sm font-semibold text-slate-700">Board</span>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="filterModule" class="select h-9 py-1 text-sm" aria-label="Filter by module">
                <option value="">All modules</option>
                @foreach($modules as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model.live="grouped"> Group by module
            </label>
            @if($canManage)
                <button type="button" wire:click="$toggle('managing')" class="btn btn-secondary h-9 px-3 py-1 text-sm">
                    {{ $managing ? 'Done' : 'Manage columns' }}
                </button>
            @endif
            <span class="text-sm font-semibold text-slate-700">{{ $project->name }}</span>
        </div>
    </div>

    @if($canManage && $managing)
        <x-ui.card class="mb-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-700">Board columns</h3>
            @foreach($this->boardColumns as $i => $col)
                @php $locked = $loop->first || $loop->last; @endphp
                <div class="flex flex-wrap items-center gap-2" wire:key="manage-{{ $col->id }}">
                    <input type="text" value="{{ $col->name }}" maxlength="60" class="input h-9 w-48 py-1 text-sm"
                           aria-label="Column name"
                           wire:change="renameColumn({{ $col->id }}, $event.target.value)">
                    <select class="select h-9 w-36 py-1 text-sm" aria-label="Stage" @disabled($locked)
                            wire:change="setStage({{ $col->id }}, $event.target.value)">
                        @foreach($stages as $key => $label)
                            <option value="{{ $key }}" @selected($col->stage === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @unless($locked)
                        <button type="button" wire:click="moveColumn({{ $col->id }}, -1)" class="btn btn-secondary h-9 px-2 py-1 text-sm" aria-label="Move left">&larr;</button>
                        <button type="button" wire:click="moveColumn({{ $col->id }}, 1)" class="btn btn-secondary h-9 px-2 py-1 text-sm" aria-label="Move right">&rarr;</button>
                        <select wire:model="deleteTarget.{{ $col->id }}" class="select h-9 w-44 py-1 text-sm" aria-label="Move tasks to">
                            <option value="">Move tasks to…</option>
                            @foreach($this->boardColumns->where('id', '!=', $col->id) as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="deleteColumn({{ $col->id }})" wire:confirm="Delete this column?" class="btn btn-secondary h-9 px-3 py-1 text-sm text-rose-600">Delete</button>
                        @error("deleteTarget.$col->id") <p class="field-error w-full">{{ $message }}</p> @enderror
                    @else
                        <span class="text-xs text-slate-400">{{ $loop->first ? 'First' : 'Last' }} column is locked</span>
                    @endunless
                </div>
            @endforeach
            <form wire:submit="addColumn" class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                <input type="text" wire:model="newColumnName" placeholder="New column name" maxlength="60" class="input h-9 w-48 py-1 text-sm">
                <select wire:model="newColumnStage" class="select h-9 w-36 py-1 text-sm" aria-label="New column stage">
                    @foreach($stages as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <button type="submit" class="btn btn-primary h-9 px-3 py-1 text-sm">Add column</button>
                @error('newColumnName') <p class="field-error w-full">{{ $message }}</p> @enderror
            </form>
        </x-ui.card>
    @endif

    @foreach($lanes as $lane)
        <div wire:key="lane-{{ $lane?->id ?? 'all' }}" class="mb-6">
            @if($lane)
                <h2 class="mb-2 text-sm font-semibold text-slate-700">{{ $lane->name }}</h2>
            @endif
            <div class="flex gap-4 overflow-x-auto pb-4">
                @foreach($this->boardColumns as $col)
                    @php $cards = ($this->columns->get($col->id) ?? collect())->when($lane, fn ($c) => $c->where('module_id', $lane->id)); @endphp
                    <div class="flex w-72 shrink-0 flex-col rounded-xl bg-gray-100 p-3 dark:bg-gray-800/60" wire:key="col-{{ $lane?->id ?? 'all' }}-{{ $col->id }}">
                        <div class="mb-3 flex items-center justify-between px-1">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $col->name }}</h3>
                            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ $cards->count() }}</span>
                        </div>

                        {{-- x-board-drag wires SortableJS whenever Alpine initialises this element (morphs, tab switches, new columns) --}}
                        <ul class="board-column min-h-20 space-y-2"
                            data-column="{{ $col->id }}"
                            data-lane="{{ $lane?->id ?? 'all' }}"
                            x-data x-board-drag>
                            @foreach($cards as $task)
                                @php
                                    $subs = $task->subtasks;
                                    $subsDone = $subs->filter(fn ($s) => $s->checklistItems->isNotEmpty() && $s->checklistItems->every->is_done)->count();
                                @endphp
                                <li wire:key="task-{{ $task->id }}"
                                    class="group rounded-lg bg-[#ffffff] dark:bg-gray-700 p-3 shadow-sm ring-1 ring-slate-900/5 dark:ring-white/10 cursor-grab active:cursor-grabbing"
                                    data-task-id="{{ $task->id }}">
                                    <p class="text-xs font-medium text-brand-600">{{ $task->module->name }}</p>
                                    <p class="text-sm font-medium text-slate-800">{{ $task->title }}</p>
                                    @if($task->estimate_hours)
                                        <p class="mt-1 text-xs text-slate-400">{{ $task->estimate_hours }}h</p>
                                    @endif
                                    <div class="mt-2 flex items-center gap-2">
                                        @if($task->client_visible)
                                            <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">Client</span>
                                        @endif
                                        @if($subs->isNotEmpty())
                                            <span class="text-xs text-slate-500">{{ $subsDone }}/{{ $subs->count() }} done</span>
                                        @endif
                                        <a href="{{ route('tasks.edit', $task) }}" wire:navigate
                                           class="ml-auto text-xs font-medium text-brand-600 opacity-0 group-hover:opacity-100">
                                            Edit
                                        </a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
