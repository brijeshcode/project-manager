                    @php $mPct = round($progress->forModule($module)); $isDone = $mStatus === 'completed'; @endphp
                    <x-ui.card :class="'mb-4 '.($isDone ? 'ring-1 ring-green-500/40' : '')" padding="p-0" data-test="module-row" data-id="{{ $module->id }}" wire:key="module-{{ $module->id }}" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                            @if ($canManage && ! $isDone)
                                <span class="module-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder" data-test="module-handle"><x-icon name="grip-vertical" /></span>
                            @endif
                            <button type="button" @click="open = !open" class="shrink-0 text-slate-400 hover:text-slate-600" title="Expand module" aria-label="Expand module" data-test="module-toggle">
                                <x-icon name="chevron-right" class="h-4 w-4 transition" ::class="open && 'rotate-90'" />
                            </button>
                            <h3 class="min-w-0 flex-1 basis-40 truncate font-display text-sm font-semibold text-slate-900"><a href="{{ route('modules.show', [$project, $module]) }}" wire:navigate class="hover:text-brand-600">{{ $module->name }}</a></h3>
                            @if ($canManage)
                                <div class="relative" x-data="{ pop: false }" @click.outside="pop = false" @keydown.escape="pop = false">
                                    <button type="button" @click="pop = !pop" class="rounded-full" title="Change priority" aria-label="Change priority" data-test="module-priority"><x-ui.priority :value="$module->priority" /></button>
                                    <div x-show="pop" style="display:none" class="absolute left-0 z-20 mt-1 w-28 rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                        @foreach (\App\Support\Look::PRIORITIES as $pv => [$pl])
                                            <button type="button" wire:click="setModulePriority({{ $module->id }}, {{ $pv }})" @click="pop = false" class="block w-full rounded px-2 py-1 text-left text-xs hover:bg-slate-100 dark:hover:bg-gray-700" data-test="set-priority-{{ $pv }}">{{ $pl }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <x-ui.priority :value="$module->priority" data-test="module-priority" />
                            @endif
                            <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$mStatus]" data-test="module-status">{{ \App\Models\Module::STATUS_LABELS[$mStatus] }}</x-ui.badge>
                            <span class="w-24 text-xs" data-test="module-deadline">@if ($module->deadline)<x-ui.deadline :date="$module->deadline" :done="$mStatus === 'completed'" format="M j, Y" />@else<span class="text-slate-500">No deadline</span>@endif</span>
                            <x-ui.assignees :users="$module->assignees" :candidates="$canManage ? $candidates : null" method="toggleModuleAssignee" :id="$module->id" data-test="module-assignees" />
                            <span class="flex flex-wrap gap-1">
                                @foreach ($module->labels as $l)
                                    <x-ui.label-chip :label="$l" />
                                @endforeach
                            </span>
                            <x-ui.progress :pct="$mPct" class="w-40" data-test="module-progress" />
                            <span class="w-14 text-right text-xs text-slate-400" data-test="module-task-count">{{ $module->tasks->count() }} {{ Str::plural('task', $module->tasks->count()) }}</span>
                            @can('manage-money')
                                @php $mb = new \App\Services\ModuleBudget($module); $mbs = $mb->summary(); @endphp
                                @if ($mbs['cap'] !== null)
                                    <span @class(['text-xs tabular-nums', 'text-slate-500' => $mbs['remaining'] >= 0, 'font-medium text-rose-600 dark:text-rose-400' => $mbs['remaining'] < 0]) data-test="module-budget-line">Used {{ $mb->format($mbs['used']) }} of {{ $mb->format($mbs['cap']) }} &middot; {{ $mbs['remaining'] < 0 ? $mb->format(-$mbs['remaining']).' over' : $mb->format($mbs['remaining']).' left' }}</span>
                                @else
                                    <span class="text-xs text-slate-500">No budget</span>
                                @endif
                            @endcan
                        </div>
                        @if (! empty($budgetBlocked[$module->id]))
                            <div class="mx-5 mb-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200" role="alert" data-test="module-over-budget">{{ $budgetBlocked[$module->id] }} Nothing was saved. <a href="{{ route('modules.show', [$project, $module]) }}" wire:navigate class="font-medium underline">Open the module</a> to set the amount it needs.</div>
                        @endif
                        <div x-show="open" x-collapse style="display:none" class="border-t border-slate-100">
                        <div class="border-b border-slate-100 px-5 py-3.5" x-data="{ editingDesc: false }">
                            @if ($canManage)
                                <div x-show="!editingDesc" class="mt-1 flex items-start gap-2">
                                    <div class="flex-1 text-xs text-slate-500">@if ($module->description)<x-ui.markdown :text="$module->description" />@else No description yet.@endif</div>
                                    <button @click="editingDesc = true" class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700">Edit</button>
                                </div>
                                <form x-show="editingDesc" style="display:none" wire:submit.prevent="saveModuleDescription({{ $module->id }})" @submit="editingDesc = false" class="mt-2 space-y-1.5">
                                    <x-ui.markdown-editor model="moduleDescriptions.{{ $module->id }}" :rows="4" :uploads="false" placeholder="Module description…" />
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-secondary h-7 px-2.5 py-1 text-xs">Save</button>
                                        <button type="button" @click="editingDesc = false" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                                    </div>
                                </form>
                            @elseif ($module->description)
                                <x-ui.markdown :text="$module->description" class="mt-1" />
                            @endif
                            @can('manage-money')
                                @php $hourly = $module->billingMode() === 'hourly'; @endphp
                                <div class="mt-2 text-xs text-slate-500" x-data="{ editingBudget: false }">
                                    <div x-show="!editingBudget" class="flex items-center gap-2">
                                        <span>Budget: {{ $module->budgetAmount() === null ? 'not set' : \App\Support\Money::format(\App\Support\Money::toCents($module->budgetAmount()), $project->currency) }}@if ($module->deadline) &middot; due {{ $module->deadline->toDateString() }}@endif</span>
                                        <button @click="editingBudget = true" class="shrink-0 font-medium text-brand-600 hover:text-brand-700">Edit budget</button>
                                    </div>
                                    <form x-show="editingBudget" style="display:none" wire:submit.prevent="saveBudget({{ $module->id }})" @submit="editingBudget = false" class="space-y-1.5">
                                        @if ($hourly)
                                            <label class="block">Budget hours<input type="number" step="0.01" min="0" wire:model="budget.{{ $module->id }}.hours" class="input h-8 text-xs"></label>
                                            <label class="block">Hourly rate ({{ $project->currency }})<input type="text" wire:model="budget.{{ $module->id }}.rate" class="input h-8 text-xs"></label>
                                        @else
                                            <label class="block">Budget amount ({{ $project->currency }})<input type="text" wire:model="budget.{{ $module->id }}.amount" class="input h-8 text-xs"></label>
                                        @endif
                                        <label class="block">Deadline<input type="date" wire:model="budget.{{ $module->id }}.deadline" class="input h-8 text-xs"></label>
                                        <label class="block">Reason<input type="text" wire:model="budget.{{ $module->id }}.reason" placeholder="Required when changing an existing value" class="input h-8 text-xs"></label>
                                        @foreach (['hours', 'rate', 'amount', 'deadline', 'reason'] as $f)
                                            @error("budget.{$module->id}.$f") <p class="field-error">{{ $message }}</p> @enderror
                                        @endforeach
                                        <div class="flex gap-2">
                                            <button type="submit" class="btn btn-secondary h-7 px-2.5 py-1 text-xs">Save budget</button>
                                            <button type="button" @click="editingBudget = false" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            @endcan
                        </div>

                        <ul class="divide-y divide-slate-100" x-data x-sortable data-sort-method="reorderTasks" data-sort-scope="{{ $module->id }}" data-test="module-task-rows">
                            @foreach ($module->tasks as $task)
                                @php $taskProgress = $progress->forTask($task); @endphp
                                <li x-data="{ open: false }" wire:key="mt-{{ $task->id }}" data-id="{{ $task->id }}" class="bg-white px-3 py-3 dark:bg-transparent">
                                    <div class="flex items-center gap-3">
                                        @if ($canManage)
                                            <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                                        @endif
                                        <button @click="open = !open" class="flex shrink-0 items-center text-left" title="Expand task" aria-label="Expand task">
                                            <x-icon name="chevron-right" class="h-4 w-4 text-slate-400 transition" ::class="open && 'rotate-90'" />
                                        </button>
                                        <x-ui.task-code :task="$task" />
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate data-test="task-link" class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 hover:text-brand-600">{{ $task->title }}</a>
                                        <x-ui.assignees :users="$task->assignees" :candidates="$canManage ? $candidates : null" method="toggleTaskAssignee" :id="$task->id" />
                                        @can('manage-money')
                                            @include('livewire.items._task-value', ['task' => $task, 'currency' => $project->currency])
                                        @endcan
                                        @foreach ($task->labels as $l)
                                            <x-ui.label-chip :label="$l" class="hidden sm:inline-flex" />
                                        @endforeach
                                        <x-ui.type-badge :type="$task->type" />
                                        <x-ui.priority :value="$task->priority" />
                                        <x-ui.deadline :date="$task->deadline" :done="$task->column?->stage === 'done'" class="text-xs" />
                                        <x-ui.stage-badge :column="$task->column" />
                                        <x-ui.progress :pct="$taskProgress" class="w-40" data-test="task-progress-bar" />
                                        @if($canManage)
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="shrink-0 text-slate-400 hover:text-brand-600" title="Edit task" aria-label="Edit task" data-test="edit-task"><x-icon name="pencil" /></a>
                                        @endif
                                    </div>

                                    <div x-show="open" x-collapse style="display:none" class="mt-4 space-y-5 pl-7">
                                      {{-- Description --}}
                                      <div>
                                          <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Description</p>
                                          @if ($canManage)
                                              <x-ui.markdown-editor model="taskDescriptions.{{ $task->id }}" :rows="3" :uploads="false" placeholder="Describe this task…" />
                                              <button wire:click="saveTaskDescription({{ $task->id }})" class="btn btn-secondary mt-1.5 h-7 px-2.5 py-1 text-xs">Save description</button>
                                          @else
                                              @if ($task->description)<x-ui.markdown :text="$task->description" />@else<p class="text-sm text-slate-600">—</p>@endif
                                          @endif
                                      </div>

                                      <div class="grid gap-5 sm:grid-cols-2">
                                        {{-- Checklist --}}
                                        <div>
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Checklist</p>
                                            <ul class="space-y-1.5" x-data x-sortable data-sort-method="reorderChecklist" data-sort-scope="{{ $task->id }}" data-sort-handle=".item-handle" data-test="task-checklist-items">
                                                @foreach ($task->checklistItems as $item)
                                                    <li wire:key="mci-{{ $item->id }}" data-id="{{ $item->id }}" class="flex items-center gap-1.5">
                                                        @if ($canManage)
                                                            <span class="item-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder" data-test="item-handle"><x-icon name="grip-vertical" /></span>
                                                        @endif
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
                                            <ul class="space-y-1.5" x-data x-sortable data-sort-method="reorderSubtasksOf" data-sort-scope="{{ $task->id }}" data-sort-handle=".item-handle" data-test="task-subtasks">
                                                @foreach ($task->subtasks as $subtask)
                                                    <li wire:key="msub-{{ $subtask->id }}" data-id="{{ $subtask->id }}" class="flex items-center gap-1.5 text-sm text-slate-700">
                                                        @if ($canManage)
                                                            <span class="item-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder" data-test="item-handle"><x-icon name="grip-vertical" /></span>
                                                        @endif
                                                        {{ $subtask->title }}
                                                    </li>
                                                @endforeach
                                                @if ($task->subtasks->isEmpty())
                                                    <li class="text-sm text-slate-400">No subtasks yet.</li>
                                                @endif
                                            </ul>
                                            @if ($canManage)
                                                <div class="mt-2" x-data="{ adding: false }">
                                                    <button type="button" x-show="!adding" @click="adding = true; $nextTick(() => $refs.sub.focus())" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700" data-test="add-subtask-link"><x-icon name="plus" /> Add subtask</button>
                                                    <form x-show="adding" style="display:none" wire:submit.prevent="addSubtask({{ $task->id }})" @submit="adding = false" class="flex gap-2" data-test="add-subtask-form">
                                                        <input type="text" x-ref="sub" wire:model="newSubtaskTitle.{{ $task->id }}" placeholder="Add subtask…" class="input h-8 flex-1 py-1 text-sm" @keydown.escape="adding = false">
                                                        <button type="submit" class="btn btn-secondary h-8 px-2.5 py-1 text-xs">Add</button>
                                                        <button type="button" @click="adding = false" class="btn btn-ghost h-8 px-2" title="Cancel" aria-label="Cancel"><x-icon name="x" /></button>
                                                    </form>
                                                </div>
                                            @endif
                                        </div>
                                      </div>

                                      {{-- Time logger --}}
                                      <div>
                                          <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Time</p>
                                          <livewire:time.logger :timeable="$task" :key="'logger-'.$task->id" />
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
                                <button type="submit" class="btn btn-secondary h-9 gap-1 whitespace-nowrap text-sm" title="Add task" data-test="add-task"><x-icon name="plus" /> Task</button>
                            </form>
                        @endif
                        </div>
                    </x-ui.card>
