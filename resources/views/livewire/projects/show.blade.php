<div>
    @php
        $checklist = app(\App\Services\ProgressService::class)->forProject($project);
        $timeUsed  = $project->timeBar() * 100;
        $deadline  = $project->start_date ? $project->deadline() : null;
    @endphp

    <div class="mb-6">
        <a href="{{ route('projects.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Projects
        </a>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="font-display text-2xl font-bold tracking-tight text-slate-900">{{ $project->name }}</h1>
                <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->currency() }}</p>
        </div>
        @unless (auth()->user()->hasRole('client'))
            <a href="{{ route('projects.edit', $project) }}" wire:navigate class="btn btn-secondary">Edit project</a>
        @endunless
    </div>

    @if ($project->description)
        <p class="mt-4 max-w-2xl text-sm leading-relaxed text-slate-600">{{ $project->description }}</p>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <h2 class="mb-4 text-sm font-semibold text-slate-900">Project health</h2>
                <x-ui.health-meter :checklist="$checklist" :time="$timeUsed" />
            </x-ui.card>

            {{-- Workspace: modules → tasks → subtasks + checklist --}}
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-display text-lg font-semibold text-slate-900">Modules & tasks</h2>
                </div>

                @if ($canManage)
                    <form wire:submit.prevent="addModule" class="card mb-4 space-y-2 p-4">
                        <div class="flex gap-2">
                            <input type="text" wire:model="newModuleName" placeholder="New module name…" class="input flex-1">
                            <button type="submit" class="btn btn-primary whitespace-nowrap">+ Module</button>
                        </div>
                        <textarea wire:model="newModuleDescription" rows="2" placeholder="Module description (optional)…" class="textarea text-sm"></textarea>
                        @error('newModuleName') <p class="field-error">{{ $message }}</p> @enderror
                    </form>
                @endif

                @forelse ($modules as $module)
                    <x-ui.card class="mb-4" padding="p-0">
                        <div class="border-b border-slate-100 px-5 py-3.5" x-data="{ editingDesc: false }">
                            <div class="flex items-center justify-between">
                                <h3 class="font-display text-sm font-semibold text-slate-900">{{ $module->name }}</h3>
                                <span class="text-xs text-slate-400">{{ $module->tasks->count() }} {{ Str::plural('task', $module->tasks->count()) }}</span>
                            </div>
                            @if ($canManage)
                                <div x-show="!editingDesc" class="mt-1 flex items-start gap-2">
                                    <p class="flex-1 text-xs text-slate-500">{{ $module->description ?: 'No description yet.' }}</p>
                                    <button @click="editingDesc = true" class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700">Edit</button>
                                </div>
                                <form x-show="editingDesc" style="display:none" wire:submit.prevent="saveModuleDescription({{ $module->id }})" @submit="editingDesc = false" class="mt-2 space-y-1.5">
                                    <textarea wire:model="moduleDescriptions.{{ $module->id }}" rows="2" class="textarea text-sm" placeholder="Module description…"></textarea>
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-secondary h-7 px-2.5 py-1 text-xs">Save</button>
                                        <button type="button" @click="editingDesc = false" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                                    </div>
                                </form>
                            @elseif ($module->description)
                                <p class="mt-1 text-xs text-slate-500">{{ $module->description }}</p>
                            @endif
                        </div>

                        <ul class="divide-y divide-slate-100">
                            @foreach ($module->tasks as $task)
                                @php $taskProgress = $progress->forTask($task); @endphp
                                <li x-data="{ open: false }" class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <button @click="open = !open" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                            <span class="truncate text-sm font-medium text-slate-800">{{ $task->title }}</span>
                                        </button>
                                        <x-ui.badge color="slate">{{ $task->board_column }}</x-ui.badge>
                                        <span class="w-10 text-right text-xs font-semibold tabular-nums text-slate-500">{{ round($taskProgress) }}%</span>
                                        @if($canManage)
                                        <a href="{{ route('tasks.edit', $task) }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700">Edit</a>
                                        @endif
                                    </div>
                                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-brand-500" style="width: {{ round($taskProgress) }}%"></div>
                                    </div>

                                    <div x-show="open" x-collapse style="display:none" class="mt-4 space-y-5 pl-7">
                                      {{-- Description --}}
                                      <div>
                                          <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Description</p>
                                          @if ($canManage)
                                              <textarea wire:model="taskDescriptions.{{ $task->id }}" rows="2" class="textarea text-sm" placeholder="Describe this task…"></textarea>
                                              <button wire:click="saveTaskDescription({{ $task->id }})" class="btn btn-secondary mt-1.5 h-7 px-2.5 py-1 text-xs">Save description</button>
                                          @else
                                              <p class="text-sm text-slate-600">{{ $task->description ?: '—' }}</p>
                                          @endif
                                      </div>

                                      <div class="grid gap-5 sm:grid-cols-2">
                                        {{-- Checklist --}}
                                        <div>
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Checklist</p>
                                            <ul class="space-y-1.5">
                                                @foreach ($task->checklistItems as $item)
                                                    <li>
                                                        <button wire:click="toggleChecklistItem({{ $item->id }})" @class(['flex w-full items-center gap-2 text-left text-sm', 'text-slate-400 line-through' => $item->is_done, 'text-slate-700' => ! $item->is_done]) @if(! $canManage) disabled @endif>
                                                            <span @class(['flex h-4 w-4 shrink-0 items-center justify-center rounded border', 'border-brand-600 bg-brand-600 text-white' => $item->is_done, 'border-slate-300' => ! $item->is_done])>
                                                                @if ($item->is_done)<svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>@endif
                                                            </span>
                                                            {{ $item->title }}
                                                        </button>
                                                    </li>
                                                @endforeach
                                                @if ($task->checklistItems->isEmpty())
                                                    <li class="text-sm text-slate-400">No items yet.</li>
                                                @endif
                                            </ul>
                                            @if ($canManage)
                                                <form wire:submit.prevent="addChecklistItem({{ $task->id }})" class="mt-2 flex gap-2">
                                                    <input type="text" wire:model="newChecklistTitle.{{ $task->id }}" placeholder="Add item…" class="input h-8 flex-1 py-1 text-sm">
                                                    <button type="submit" class="btn btn-secondary h-8 px-2.5 py-1 text-xs">Add</button>
                                                </form>
                                            @endif
                                        </div>

                                        {{-- Subtasks --}}
                                        <div>
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Subtasks</p>
                                            <ul class="space-y-1.5">
                                                @foreach ($task->subtasks as $subtask)
                                                    <li class="flex items-center gap-2 text-sm text-slate-700">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                                                        {{ $subtask->title }}
                                                    </li>
                                                @endforeach
                                                @if ($task->subtasks->isEmpty())
                                                    <li class="text-sm text-slate-400">No subtasks yet.</li>
                                                @endif
                                            </ul>
                                            @if ($canManage)
                                                <form wire:submit.prevent="addSubtask({{ $task->id }})" class="mt-2 flex gap-2">
                                                    <input type="text" wire:model="newSubtaskTitle.{{ $task->id }}" placeholder="Add subtask…" class="input h-8 flex-1 py-1 text-sm">
                                                    <button type="submit" class="btn btn-secondary h-8 px-2.5 py-1 text-xs">Add</button>
                                                </form>
                                            @endif
                                        </div>
                                      </div>
                                    </div>
                                </li>
                            @endforeach
                            @if ($module->tasks->isEmpty())
                                <li class="px-5 py-3 text-sm text-slate-400">No tasks in this module yet.</li>
                            @endif
                        </ul>

                        @if ($canManage)
                            <form wire:submit.prevent="addTask({{ $module->id }})" class="flex gap-2 border-t border-slate-100 px-5 py-3">
                                <input type="text" wire:model="newTaskTitle.{{ $module->id }}" placeholder="Add a task…" class="input h-9 flex-1 text-sm">
                                <button type="submit" class="btn btn-secondary h-9 whitespace-nowrap text-sm">+ Task</button>
                            </form>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.empty-state title="No modules yet" message="Modules group related tasks — like “Checkout” or “Onboarding”. Add one to start building the plan.">
                    </x-ui.empty-state>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <x-ui.card>
                <h2 class="mb-3 text-sm font-semibold text-slate-900">Details</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Start date</dt>
                        <dd class="font-medium text-slate-900">{{ $project->start_date?->format('M j, Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Projected deadline</dt>
                        <dd class="font-medium text-slate-900">{{ $deadline?->format('M j, Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Buffer</dt>
                        <dd class="font-medium text-slate-900">{{ rtrim(rtrim(number_format((float) $project->buffer_percent, 2), '0'), '.') }}%</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-3 text-sm font-semibold text-slate-900">Team</h2>
                @if ($project->members->isEmpty())
                    <p class="text-sm text-slate-500">No members assigned yet.</p>
                @else
                    <ul class="space-y-2.5">
                        @foreach ($project->members as $member)
                            @php $mi = collect(explode(' ', trim($member->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode(''); @endphp
                            <li class="flex items-center gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $mi }}</span>
                                <span class="text-sm font-medium text-slate-800">{{ $member->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
