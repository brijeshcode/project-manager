@php
    $filterDefs = [
        'modules'   => ['Module', 'moduleIds', $modules->pluck('name', 'id')->all()],
        'assignees' => ['Assignee', 'assigneeIds', ['none' => 'Unassigned'] + $people->pluck('name', 'id')->all()],
        'types'     => ['Type', 'typeIds', $types->pluck('name', 'id')->all()],
        'labels'    => ['Label', 'labelIds', $allLabels->pluck('name', 'id')->all()],
        'prios'     => ['Priority', 'prios', [2 => 'High', 1 => 'Medium', 0 => 'Low']],
    ];
    $active = collect($filterDefs)->flatMap(fn ($d, $k) => collect($this->{$d[1]})->map(fn ($v) => ['key' => $k, 'v' => (string) $v, 'name' => $d[0], 'label' => $d[2][$v] ?? $v]));
    $hasFilters = $active->isNotEmpty() || trim($search) !== '';
    $stageDot = ['not_started' => 'bg-slate-400', 'active' => 'bg-blue-500', 'review' => 'bg-amber-400', 'done' => 'bg-green-500'];
    $stripe = [0 => 'bg-slate-300 dark:bg-gray-500', 1 => 'bg-amber-400', 2 => 'bg-rose-500'];
@endphp
{{-- The board fills the viewport below its own top edge (fit()); every column scrolls on its own. Grouped swimlanes flow down the page instead. --}}
<div x-data="{ h: 0, fit() { this.h = Math.max(440, window.innerHeight - this.$el.getBoundingClientRect().top - 16) } }" x-init="fit()" @resize.window.debounce.100ms="fit()"
     class="flex flex-col" @if(! $grouped) :style="{ height: h + 'px' }" @endif data-test="board">
    {{-- toolbar --}}
    <div class="mb-2 flex flex-wrap items-center gap-2" data-test="board-toolbar">
        @unless($embedded)
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700" data-test="board-back">&larr; Workspace</a>
            <span class="text-slate-300">/</span>
            <span class="text-sm font-semibold text-slate-700">{{ $project->name }}</span>
        @endunless
        <div class="relative w-full min-w-0 sm:w-44">
            <x-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search tasks" aria-label="Search tasks" class="input h-9 py-1 pl-8 text-sm" data-test="board-search">
        </div>
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 text-sm dark:border-gray-700" role="group" aria-label="Board scope" data-test="board-scope">
            @foreach(['sprint' => 'Active sprint', 'all' => 'All'] as $k => $lbl)
                <button type="button" wire:click="$set('scope', '{{ $k }}')" aria-pressed="{{ $scope === $k ? 'true' : 'false' }}" data-test="scope-{{ $k }}" @class(['h-9 px-3 font-medium', 'bg-brand-600 text-white' => $scope === $k, 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300' => $scope !== $k])>{{ $lbl }}</button>
            @endforeach
        </div>
        @foreach($filterDefs as $key => [$name, $prop, $opts])
            @php $n = count($this->{$prop}); @endphp
            <div class="relative" x-data="{ o: false }" @click.outside="o = false" @keydown.escape="o = false" wire:key="filter-{{ $key }}" data-test="filter-{{ $key }}">
                <button type="button" @click="o = !o" :aria-expanded="o" @class(['btn btn-secondary h-9 gap-1 px-2.5 py-1 text-sm', '!ring-brand-500' => $n > 0])>
                    {{ $name }}
                    @if($n)<span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-[11px] font-semibold text-white" data-test="filter-count">{{ $n }}</span>@endif
                    <x-icon name="chevron-down" class="h-3.5 w-3.5 text-slate-400" />
                </button>
                <div x-show="o" style="display:none" class="absolute left-0 z-30 mt-1 max-h-72 w-56 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800" data-test="filter-pop-{{ $key }}">
                    @forelse($opts as $val => $lbl)
                        <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-100 dark:hover:bg-gray-700">
                            <input type="checkbox" wire:model.live="{{ $prop }}" value="{{ $val }}" class="rounded border-slate-300 text-brand-600" data-test="opt-{{ $key }}-{{ $val }}">
                            <span class="truncate">{{ $lbl }}</span>
                        </label>
                    @empty
                        <p class="px-1.5 py-1 text-xs text-slate-400">Nothing to choose yet.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
        <button type="button" wire:click="$toggle('grouped')" role="switch" aria-checked="{{ $grouped ? 'true' : 'false' }}" class="inline-flex h-9 items-center gap-2 px-1 text-sm text-slate-600" data-test="group-toggle">
            <span @class(['relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors', 'bg-brand-600' => $grouped, 'bg-slate-300 dark:bg-gray-600' => ! $grouped])>
                <span @class(['inline-block h-4 w-4 rounded-full bg-white shadow transition-transform', 'translate-x-4' => $grouped, 'translate-x-0.5' => ! $grouped])></span>
            </span>
            Group by module
        </button>
        @if($canManage)
            <button type="button" wire:click="$toggle('managing')" @class(['btn btn-secondary ml-auto h-9 w-9 px-0 py-0', '!ring-brand-500' => $managing]) data-tip="{{ $managing ? 'Done managing columns' : 'Manage columns' }}" aria-label="Manage columns" aria-pressed="{{ $managing ? 'true' : 'false' }}" data-test="manage-columns"><x-icon name="settings" /></button>
        @endif
    </div>

    @if($hasFilters)
        <div class="mb-2 flex flex-wrap items-center gap-1.5 text-xs" data-test="filter-chips">
            @if(trim($search) !== '')
                <button type="button" wire:click="$set('search', '')" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700 hover:bg-slate-200 dark:bg-gray-700 dark:text-gray-200" data-test="chip" aria-label="Remove search filter">Search: {{ $search }} <x-icon name="x" class="h-3 w-3" /></button>
            @endif
            @foreach($active as $c)
                <button type="button" wire:click="removeFilter('{{ $c['key'] }}', '{{ $c['v'] }}')" wire:key="chip-{{ $c['key'] }}-{{ $c['v'] }}" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700 hover:bg-slate-200 dark:bg-gray-700 dark:text-gray-200" data-test="chip" aria-label="Remove {{ $c['name'] }} filter {{ $c['label'] }}">{{ $c['name'] }}: {{ $c['label'] }} <x-icon name="x" class="h-3 w-3" /></button>
            @endforeach
            <button type="button" wire:click="clearFilters" class="font-medium text-brand-600 hover:underline" data-test="clear-filters">Clear all</button>
        </div>
    @endif

    @if($fellBack && $scope === 'all')
        <p class="mb-2 text-xs text-slate-500" data-test="board-fallback">No active sprint, showing all tasks.</p>
    @endif
    @if($scope === 'sprint' && ! $this->activeSprint)
        <p class="mb-2 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200" data-test="board-no-sprint">No active sprint. Start one in the Sprint tab, or switch to All.</p>
    @endif

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

    <div @class(['min-h-0 flex-1', 'overflow-y-auto' => $grouped])>
    @foreach($lanes as $lane)
        <div wire:key="lane-{{ $lane?->id ?? 'all' }}" @class(['mb-4' => $lane, 'h-full' => ! $lane])>
            @if($lane)
                <h2 class="mb-1.5 text-sm font-semibold text-slate-700">{{ $lane->name }}</h2>
            @endif
            <div @class(['flex items-stretch gap-3 overflow-x-auto pb-2', 'h-full' => ! $lane, 'max-h-[28rem]' => $lane])>
                @foreach($this->boardColumns as $col)
                    @php $cards = ($this->columns->get($col->id) ?? collect())->when($lane, fn ($c) => $c->where('module_id', $lane->id)); @endphp
                    <div data-col class="flex min-h-0 min-w-[16rem] max-w-80 flex-1 basis-64 shrink-0 flex-col rounded-xl bg-gray-100 dark:bg-gray-800/60" wire:key="col-{{ $lane?->id ?? 'all' }}-{{ $col->id }}" data-stage="{{ $col->stage }}">
                        <div class="flex shrink-0 items-center gap-2 px-3 py-2">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $stageDot[$col->stage] ?? 'bg-slate-400' }}" data-tip="{{ $stages[$col->stage] ?? $col->stage }}"></span>
                            <h3 class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $col->name }}</h3>
                            <span data-count class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-slate-500">{{ $cards->count() }}</span>
                        </div>

                        {{-- x-board-drag wires SortableJS whenever Alpine initialises this element (morphs, tab switches, new columns) --}}
                        <ul class="board-column min-h-16 flex-1 space-y-1.5 overflow-y-auto px-2 pb-2"
                            data-column="{{ $col->id }}"
                            data-lane="{{ $lane?->id ?? 'all' }}"
                            x-data x-board-drag>
                            @foreach($cards as $task)
                                @php
                                    $subs = $task->subtasks;
                                    $subsDone = $subs->filter(fn ($x) => $x->is_done || ($x->checklistItems->isNotEmpty() && $x->checklistItems->every->is_done))->count();
                                    $items = $task->checklistItems;
                                    $itemsDone = $items->where('is_done', true)->count();
                                    $tc = $task->type && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $task->type->color) ? $task->type->color : '#6b7280';
                                @endphp
                                <li wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-test="board-card"
                                    class="board-card group relative cursor-grab rounded-lg bg-[#ffffff] py-2 pl-3 pr-2 shadow-sm ring-1 ring-slate-900/5 dark:bg-gray-700 dark:ring-white/10">
                                    <span class="absolute inset-y-1.5 left-0 w-[3px] rounded-r {{ $stripe[$task->priority] ?? $stripe[0] }}" aria-hidden="true"></span>
                                    <div class="flex items-center gap-1.5">
                                        @if($task->type)
                                            <span class="inline-flex shrink-0" style="color: {{ $tc }}" data-tip="{{ $task->type->name }}" data-type="{{ $task->type->name }}" aria-label="{{ $task->type->name }}" role="img" tabindex="0"><x-ui.glyph :value="$task->type->icon ?: 'circle-dot'" class="h-3.5 w-3.5" /></span>
                                        @endif
                                        <x-ui.priority :value="$task->priority" :dot="true" />
                                        <span class="min-w-0 truncate rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-600 dark:bg-gray-600 dark:text-gray-200" data-test="card-module">{{ $task->module->name }}</span>
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate draggable="false" data-no-drag
                                           class="ml-auto shrink-0 rounded p-0.5 text-slate-400 opacity-0 transition hover:bg-slate-100 hover:text-brand-600 focus-visible:opacity-100 group-hover:opacity-100 dark:hover:bg-gray-600" data-tip="Edit task" aria-label="Edit task" data-test="card-edit">
                                            <x-icon name="pencil" class="h-3.5 w-3.5" />
                                        </a>
                                    </div>
                                    <a href="{{ route('tasks.show', $task) }}" wire:navigate draggable="false" data-test="card-link" class="mt-1 line-clamp-2 break-words text-[13px] font-medium leading-snug text-slate-800 hover:text-brand-600">{{ $task->title }}</a>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500" data-test="card-meta">
                                        <x-ui.deadline :date="$task->deadline" :done="$col->stage === 'done'" class="inline-flex items-center rounded bg-slate-100 px-1.5 py-px text-[11px] dark:bg-gray-600/60" data-test="card-deadline" />
                                        @if($subs->isNotEmpty())
                                            <span class="inline-flex items-center gap-0.5" data-tip="Subtasks done" data-test="card-subtasks"><x-icon name="square-check-big" class="h-3 w-3" />{{ $subsDone }}/{{ $subs->count() }}</span>
                                        @endif
                                        @if($items->isNotEmpty())
                                            <span class="inline-flex items-center gap-0.5" data-tip="Checklist done" data-test="card-checklist"><x-icon name="list-checks" class="h-3 w-3" />{{ $itemsDone }}/{{ $items->count() }}</span>
                                        @endif
                                        @if($task->labels->isNotEmpty())
                                            <span class="inline-flex items-center gap-1" data-test="card-labels">
                                                @foreach($task->labels as $l)
                                                    <span class="h-2 w-2 rounded-full" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $l->color) ? $l->color : '#6b7280' }}" data-tip="{{ $l->name }}" data-label="{{ $l->name }}" aria-label="Label {{ $l->name }}" role="img" tabindex="0"></span>
                                                @endforeach
                                            </span>
                                        @endif
                                        @if($task->client_visible)
                                            <span class="inline-flex text-sky-600 dark:text-sky-400" data-tip="Visible to the client" aria-label="Visible to the client" role="img" data-test="card-client"><x-icon name="globe" class="h-3.5 w-3.5" /></span>
                                        @endif
                                        <x-ui.assignees class="ml-auto" :users="$task->assignees" :candidates="$candidates" method="toggleTaskAssignee" :id="$task->id" size="xs" :max="3" ring="ring-2 ring-white dark:ring-gray-700" />
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
</div>
