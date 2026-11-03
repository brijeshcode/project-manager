<div>
    @php
        $stage = $task->column?->stage;
        $initials = fn ($u) => collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
        $overdue = $task->deadline && $task->deadline->isPast() && $stage !== 'done';
    @endphp

    <div class="mb-6">
        <a href="{{ route('modules.show', [$project, $module]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; {{ $module->name }}
        </a>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display text-2xl font-bold tracking-tight text-slate-900">{{ $task->title }}</h1>
                <x-ui.badge color="slate" data-test="column-badge">{{ $task->column?->name }}@if ($stage) <span class="text-slate-400">&middot; {{ str_replace('_', ' ', $stage) }}</span>@endif</x-ui.badge>
                @if ($task->status->value === 'draft') <x-ui.badge color="amber">Draft</x-ui.badge> @endif
                @if ($task->client_visible) <x-ui.badge color="brand">Visible to client</x-ui.badge> @endif
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->name }} &middot; {{ $module->name }}</p>
        </div>
        @if ($canEdit && ! $editing)
            <button wire:click="edit" class="btn btn-primary" data-test="edit-task">Edit task</button>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($editing)
                <form wire:submit="save" class="card space-y-4 p-5">
                    <div>
                        <label class="label">Title</label>
                        <input type="text" wire:model="title" class="input">
                        @error('title') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Description</label>
                        <x-ui.markdown-editor model="description" :rows="8" />
                        @error('description') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="label">Board column</label>
                            <select wire:model="columnId" class="select">
                                @foreach ($columns as $col) <option value="{{ $col->id }}">{{ $col->name }}</option> @endforeach
                            </select>
                            @error('columnId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Priority</label>
                            <select wire:model="priority" class="select">
                                <option value="0">Low</option><option value="1">Medium</option><option value="2">High</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Status</label>
                            <select wire:model="status" class="select"><option value="draft">Draft</option><option value="published">Published</option></select>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Deadline</label>
                            <input type="date" wire:model="deadline" class="input">
                            @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Reason for deadline change <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="text" wire:model="deadlineReason" class="input">
                        </div>
                    </div>
                    @if ($canCost)
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="label">Scope</label>
                                <select wire:model.live="scope" class="select"><option value="in">In scope</option><option value="out">Out of scope</option></select>
                            </div>
                            <div>
                                <label class="label">Estimate (hours)</label>
                                <input type="number" step="0.5" min="0" wire:model="estimate" class="input">
                                @error('estimate') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label">Price <span class="font-normal text-slate-400">(out of scope)</span></label>
                                <input type="number" step="0.01" min="0" wire:model="price" class="input" @disabled($scope !== 'out')>
                                @error('price') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                    <div>
                        <label class="label">Assignees</label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            @forelse ($candidates as $u)
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="assigneeIds" value="{{ $u->id }}" class="rounded border-slate-300 text-brand-600"> {{ $u->name }}</label>
                            @empty
                                <p class="text-sm text-slate-400">No project members yet.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <label class="label">Labels</label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            @foreach ($allLabels as $l)
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="labelIds" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600"> <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $l->color }}"></span> {{ $l->name }}</label>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input type="text" wire:model="newLabel" wire:keydown.enter.prevent="addLabel" placeholder="New label…" class="input h-8 max-w-xs text-sm">
                            <input type="color" wire:model="newLabelColor" class="h-8 w-10 rounded border border-slate-300">
                            <button type="button" wire:click="addLabel" class="btn btn-secondary h-8 px-2.5 py-1 text-xs">Add label</button>
                        </div>
                        @error('newLabel') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap gap-6">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="clientVisible" class="rounded border-slate-300 text-brand-600"> Visible to the client in their portal</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isIdea" class="rounded border-slate-300 text-brand-600"> Idea / backlog item</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="btn btn-primary">Save</button>
                        <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                        <a href="{{ route('tasks.edit', $task) }}" wire:navigate class="ml-auto text-xs text-slate-500 hover:text-slate-700">Testing add-on and more &rarr;</a>
                    </div>
                </form>
            @elseif ($task->description)
                <x-ui.card>
                    <x-ui.markdown :text="$task->description" />
                </x-ui.card>
            @elseif ($canEdit)
                <x-ui.card class="space-y-2" data-test="empty-description">
                    <p class="text-sm font-medium text-slate-700">No description yet. Add one:</p>
                    <x-ui.markdown-editor model="description" :rows="5" />
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                    <button wire:click="saveDescription" class="btn btn-primary h-8 px-3 py-1 text-xs">Save description</button>
                </x-ui.card>
            @else
                <x-ui.card><p class="text-sm text-slate-400">No description yet.</p></x-ui.card>
            @endif

            {{-- Subtasks --}}
            <x-ui.card padding="p-0">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="font-display font-semibold text-slate-900">Subtasks <span class="text-sm font-normal text-slate-400">({{ $subtasks->count() }})</span></h2>
                </div>
                <ul class="divide-y divide-slate-100" data-test="subtasks">
                    @forelse ($subtasks as $s)
                        <li @class(['flex items-center gap-3 px-5 py-2.5', 'bg-brand-50/50' => $subtaskId === $s->id])>
                            <button wire:click="openSubtask({{ $s->id }})" class="min-w-0 flex-1 truncate text-left text-sm font-medium text-slate-800 hover:text-brand-600">{{ $s->title }}</button>
                            @if ($s->comments_count) <span class="text-xs text-slate-400">{{ $s->comments_count }} {{ Str::plural('comment', $s->comments_count) }}</span> @endif
                            <span class="flex -space-x-1">
                                @foreach ($s->assignees as $u)
                                    <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700 ring-2 ring-white">{{ $initials($u) }}</span>
                                @endforeach
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm text-slate-400">No subtasks yet.</li>
                    @endforelse
                </ul>
                @if ($canEdit)
                    <form wire:submit="addSubtask" class="flex gap-2 border-t border-slate-100 px-5 py-3">
                        <input type="text" wire:model="newSubtask" placeholder="Add a subtask…" class="input h-9 flex-1 text-sm">
                        <button type="submit" class="btn btn-secondary h-9 whitespace-nowrap text-sm">+ Subtask</button>
                    </form>
                    @error('newSubtask') <p class="field-error px-5 pb-3">{{ $message }}</p> @enderror
                @endif
            </x-ui.card>

            {{-- Subtask panel (inside the task page) --}}
            @if ($sub)
                <div class="card space-y-4 border-brand-200 p-5" data-test="subtask-panel">
                    <div class="flex items-start justify-between gap-3">
                        @if ($subEditing)
                            <input type="text" wire:model="subTitle" class="input font-semibold">
                        @else
                            <h3 class="font-display text-lg font-semibold text-slate-900">{{ $sub->title }}</h3>
                        @endif
                        <div class="flex shrink-0 gap-2">
                            @if ($canEdit && ! $subEditing)
                                <button wire:click="editSubtask" class="btn btn-secondary h-8 px-3 py-1 text-xs" data-test="edit-subtask">Edit</button>
                                <button wire:click="deleteSubtask({{ $sub->id }})" wire:confirm="Delete this subtask?" class="btn btn-ghost h-8 px-3 py-1 text-xs">Delete</button>
                            @endif
                            <button wire:click="closeSubtask" class="btn btn-ghost h-8 px-3 py-1 text-xs">Close</button>
                        </div>
                    </div>
                    @error('subTitle') <p class="field-error">{{ $message }}</p> @enderror

                    @if ($subEditing)
                        <div>
                            <label class="label">Description</label>
                            <x-ui.markdown-editor model="subDescription" :rows="6" />
                            @error('subDescription') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Assignees</label>
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                @foreach ($candidates as $u)
                                    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="subAssigneeIds" value="{{ $u->id }}" class="rounded border-slate-300 text-brand-600"> {{ $u->name }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="label">Labels</label>
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                @foreach ($allLabels as $l)
                                    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="subLabelIds" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600"> <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $l->color }}"></span> {{ $l->name }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="saveSubtask" class="btn btn-primary">Save</button>
                            <button wire:click="$set('subEditing', false)" class="btn btn-ghost">Cancel</button>
                        </div>
                    @else
                        @if ($sub->description)
                            <x-ui.markdown :text="$sub->description" />
                        @else
                            <p class="text-sm text-slate-400">No description yet.</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Assignees</span>
                                @forelse ($sub->assignees as $u)
                                    <span class="inline-flex items-center gap-1"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700">{{ $initials($u) }}</span>{{ $u->name }}</span>
                                @empty
                                    <span class="text-slate-400">Unassigned</span>
                                @endforelse
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Labels</span>
                                @forelse ($sub->labels as $l)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                                @empty
                                    <span class="text-slate-400">None</span>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    @unless (auth()->user()->hasRole('client'))
                        @if ($sub->attachments->isNotEmpty())
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-slate-400">Attachments</p>
                                <ul class="space-y-0.5 text-sm">
                                    @foreach ($sub->attachments as $a)
                                        <li><a href="{{ $a->url() }}" class="text-brand-600 hover:underline">{{ $a->name }}</a></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endunless

                    <div>
                        <h4 class="mb-2 font-display text-sm font-semibold text-slate-900">Subtask timeline</h4>
                        @include('livewire.tasks._timeline', ['items' => $subTimeline, 'scope' => 'subtask', 'bodyModel' => 'subCommentBody'])
                    </div>
                </div>
            @endif

            {{-- Task timeline --}}
            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                @include('livewire.tasks._timeline', ['items' => $timeline, 'scope' => 'task', 'bodyModel' => 'commentBody'])
            </div>
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-4">
            <x-ui.card class="space-y-4 text-sm">
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Column</p>
                    @if ($canEdit)
                        <select wire:change="moveColumn($event.target.value)" class="select h-9 text-sm" data-test="column-select">
                            @foreach ($columns as $col) <option value="{{ $col->id }}" @selected($col->id === $task->board_column_id)>{{ $col->name }} ({{ str_replace('_', ' ', $col->stage) }})</option> @endforeach
                        </select>
                    @else
                        <p>{{ $task->column?->name }}</p>
                    @endif
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Assignees</p>
                    @forelse ($assignees as $u)
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700">{{ $initials($u) }}</span>
                            {{ $u->name }}
                        </div>
                    @empty
                        <p class="text-slate-400">Unassigned</p>
                    @endforelse
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Labels</p>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($labels as $l)
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                        @empty
                            <p class="text-slate-400">None</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Deadline</p>
                    @if ($task->deadline)
                        <span data-test="deadline" @class(['font-medium text-rose-600' => $overdue])>{{ $task->deadline->format('M j, Y') }}</span>
                    @else
                        <p class="text-slate-400">Not set</p>
                    @endif
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Priority</p>
                    <p>{{ ['Low', 'Medium', 'High'][$task->priority] ?? $task->priority }}</p>
                </div>
                @if ($canCost)
                    <div>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Estimate</p>
                        <p>{{ $task->estimate_hours }} h @if ($task->scope->value === 'out') &middot; out of scope{{ $task->price !== null ? ', price '.number_format($task->price, 2) : '' }} @endif</p>
                    </div>
                @endif
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">Attachments</p>
                    @forelse ($attachments as $a)
                        <p><a href="{{ $a->url() }}" class="text-brand-600 hover:underline">{{ $a->name }}</a></p>
                    @empty
                        <p class="text-slate-400">None</p>
                    @endforelse
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>
