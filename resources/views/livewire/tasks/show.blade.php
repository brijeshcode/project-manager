<div>
    @php
        $stage = $task->column?->stage;
        $done = $subtasks->where('is_done', true)->count();
        $total = $subtasks->count();
        $initials = fn ($u) => collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    @endphp

    @unless ($embedded)
    <x-ui.breadcrumb :items="[['Projects', route('projects.index')], [$project->name, route('projects.show', ['project' => $project, 'tab' => 'modules'])], [$module->name, route('modules.show', [$project, $module])], [$task->code().' '.$task->title, null]]" />
    @endunless

    <div class="flex flex-col gap-3">
        @include('livewire.items._title', ['heading' => $task->title, 'canEdit' => $canEdit, 'code' => $task->code()])
        <div class="flex flex-wrap items-center gap-2 sm:pl-7">
            <x-ui.stage-badge :column="$task->column" data-test="column-badge" />
            <x-ui.type-badge :type="$task->type" data-test="type-badge" />
            <x-ui.priority :value="$task->priority" data-test="priority-badge" />
            @if ($task->status->value === 'draft') <x-ui.badge color="amber">Draft</x-ui.badge> @endif
            @if ($task->client_visible) <x-ui.badge color="brand">Visible to client</x-ui.badge> @endif
            @if ($canMoney)
                @if ($task->isAddon())
                    <x-ui.badge :color="$task->addon_accepted_at ? 'green' : 'amber'" data-test="addon-badge">{{ $task->addon_accepted_at ? 'Accepted add-on' : 'Proposed add-on' }} {{ $task->code() }}</x-ui.badge>
                @elseif (! $mb->hourly() && $task->budget_amount === null)
                    <x-ui.badge color="amber" data-test="unpriced">Unpriced</x-ui.badge>
                @endif
            @endif
            <span class="text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->name }} &middot; {{ $module->name }}</span>
            @unless ($embedded)
            <span class="ml-auto flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                <button type="button" @click="navigator.clipboard.writeText(location.href.split('#')[0]); copied = true; setTimeout(() => copied = false, 1500)" class="btn btn-secondary h-8 px-2 py-1" title="Copy link to this task" aria-label="Copy link to this task" data-test="copy-link"><x-icon name="link" /><span x-show="copied" style="display:none" class="ml-1 text-xs text-emerald-600">Copied</span></button>
                @if ($canCopy)
                    <a href="{{ route('tasks.create', ['copy' => $task->id]) }}" wire:navigate class="btn btn-secondary h-8 gap-1 px-3 py-1 text-xs" title="Copy this task" aria-label="Copy this task" data-test="copy"><x-icon name="copy" /> Copy</a>
                    <a href="{{ route('tasks.create', ['module' => $module->id]) }}" wire:navigate class="btn btn-primary h-8 gap-1 px-3 py-1 text-xs" title="New task in this module" aria-label="New task in this module" data-test="new-task"><x-icon name="plus" /> New task</a>
                @endif
            </span>
            @endunless
        </div>
    </div>

    <div @class(['mt-6 grid gap-6', 'lg:grid-cols-3' => ! $embedded, 'task-grid' => $embedded])>
        <div @class(['min-w-0 space-y-6', 'lg:col-span-2' => ! $embedded])>
            @include('livewire.items._description', ['text' => $task->description, 'canEdit' => $canEdit])

            <livewire:checklist.panel :checklistable="$task" :key="'checklist-'.$task->id" />

            {{-- Subtask list --}}
            <x-ui.card padding="p-0" data-test="subtask-list">
                <div class="border-b border-slate-100 px-5 py-3">
                    <div class="flex items-center justify-between">
                        <h2 class="font-display text-sm font-semibold text-slate-900">Subtasks <span class="font-normal text-slate-400" data-test="subtask-count">{{ $done }}/{{ $total }} done</span></h2>
                    </div>
                    @if ($total)
                        <x-ui.progress :pct="$done / $total * 100" :label="false" class="mt-2 max-w-xs" data-test="subtask-bar" />
                    @endif
                </div>
                <ul class="divide-y divide-slate-100" data-test="subtasks" x-data x-sortable data-sort-method="reorderSubtasks">
                    @forelse ($subtasks as $s)
                        <li wire:key="subtask-{{ $s->id }}" data-id="{{ $s->id }}" class="flex items-center gap-3 bg-white px-3 py-2.5 dark:bg-transparent">
                            @if ($canEdit)
                                <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                            @endif
                            <input type="checkbox" @checked($s->is_done) @disabled(! $canEdit) wire:click="toggleSubtask({{ $s->id }})" class="rounded border-slate-300 text-brand-600" aria-label="Done">
                            <a href="{{ route('subtasks.show', $s) }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm font-medium hover:text-brand-600', 'text-slate-800' => ! $s->is_done, 'text-slate-400 line-through' => $s->is_done])>{{ $s->title }}</a>
                            @if ($s->comments_count) <span class="text-xs text-slate-400">{{ $s->comments_count }} {{ Str::plural('comment', $s->comments_count) }}</span> @endif
                            <x-ui.assignees :users="$s->assignees" />
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm text-slate-400">No subtasks yet.</li>
                    @endforelse
                </ul>
                @if ($canEdit)
                    <div class="border-t border-slate-100 px-5 py-3" x-data="{ adding: false }" @subtask-added.window="adding = false">
                        <button type="button" x-show="!adding" @click="adding = true; $nextTick(() => $refs.sub.focus())" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700" data-test="add-subtask-link"><x-icon name="plus" /> Add subtask</button>
                        <form x-show="adding" style="display:none" wire:submit="addSubtask" class="flex gap-2" data-test="add-subtask-form">
                            <input type="text" x-ref="sub" wire:model="newSubtask" placeholder="Add a subtask…" class="input h-9 flex-1 text-sm" @keydown.escape="adding = false">
                            <button type="submit" class="btn btn-secondary h-9 whitespace-nowrap text-sm">Add</button>
                            <button type="button" @click="adding = false" class="btn btn-ghost h-9 px-2" title="Cancel" aria-label="Cancel"><x-icon name="x" /></button>
                        </form>
                        @error('newSubtask') <p class="field-error pt-2">{{ $message }}</p> @enderror
                    </div>
                @endif
            </x-ui.card>

            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                @include('livewire.items._timeline', ['authorId' => $task->creator_id])
            </div>
        </div>

        {{-- Sidebar --}}
        <aside>
            <x-ui.card class="text-sm" padding="p-4">
                <x-ui.side-section title="Progress" name="progress">
                    <x-ui.progress :pct="$pct" data-test="task-progress" />
                </x-ui.side-section>

                <x-ui.side-section title="Board column" name="column">
                    @if ($canEdit)
                        <select wire:change="moveColumn($event.target.value)" class="select h-9 text-sm" data-test="column-select">
                            @foreach ($columns as $col) <option value="{{ $col->id }}" @selected($col->id === $task->board_column_id)>{{ $col->name }} ({{ str_replace('_', ' ', $col->stage) }})</option> @endforeach
                        </select>
                    @else
                        <p>{{ $task->column?->name }}</p>
                    @endif
                </x-ui.side-section>

                <x-ui.side-section title="Sprint" name="sprint">
                    @php $locked = $task->column?->stage === 'done' || $task->sprint?->status === 'completed'; @endphp
                    @if ($canEdit && ! $locked)
                        <select wire:change="setSprint($event.target.value)" class="select h-9 text-sm" aria-label="Sprint" data-test="sprint-select">
                            <option value="0">None</option>
                            @foreach ($sprints as $sp) <option value="{{ $sp->id }}" @selected($sp->id === $task->sprint_id)>{{ $sp->name }} ({{ $sp->status }})</option> @endforeach
                        </select>
                    @else
                        <p data-test="sprint-side">{{ $task->sprint ? $task->sprint->name.($task->sprint->status === 'completed' ? ' (completed)' : '') : 'None' }}</p>
                    @endif
                </x-ui.side-section>

                @include('livewire.items._sidebar-people', ['canEdit' => $canEdit])

                <x-ui.side-section title="Deadline" name="deadline" :panel="$panel" :can="$canEdit">
                    @if ($task->deadline)
                        <x-ui.deadline :date="$task->deadline" :done="$stage === 'done'" format="M j, Y" data-test="deadline" />
                    @else
                        <p class="text-slate-400">Not set</p>
                    @endif
                    <x-slot:editor>
                        <input type="date" wire:model="deadline" class="input h-8 text-sm">
                        @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
                        <input type="text" wire:model="deadlineReason" placeholder="Reason (optional)" class="input mt-1.5 h-8 text-sm">
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Type" name="type" :panel="$panel" :can="$canEdit">
                    <x-ui.type-badge :type="$task->type" data-test="type-side" />
                    <x-slot:editor>
                        <select wire:model="typeId" class="select h-8 text-sm" data-test="type-select">
                            @foreach ($types as $o) <option value="{{ $o->id }}">{{ $o->name }}</option> @endforeach
                        </select>
                        @error('typeId') <p class="field-error">{{ $message }}</p> @enderror
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Priority" name="priority" :panel="$panel" :can="$canEdit">
                    <x-ui.priority :value="$task->priority" />
                    <x-slot:editor>
                        <select wire:model="priority" class="select h-8 text-sm"><option value="0">Low</option><option value="1">Medium</option><option value="2">High</option></select>
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Dates" name="dates" :panel="$panel" :can="$canEdit">
                    <dl class="space-y-0.5" data-test="dates">
                        <div class="flex justify-between"><dt class="text-slate-500">Started</dt><dd data-test="started-at">{{ $task->started_at?->format('M j, Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Completed</dt><dd data-test="completed-at">{{ $task->completed_at?->format('M j, Y') ?? '—' }}</dd></div>
                    </dl>
                    @if (($took = $task->daysTaken()) !== null)
                        <p class="mt-1 text-xs text-slate-500" data-test="took">{{ $took === 0 ? 'Done the same day' : 'Took '.$took.' '.Str::plural('day', $took) }}</p>
                    @endif
                    <x-slot:editor>
                        <label class="block text-xs text-slate-500">Started<input type="date" wire:model="startedAt" class="input mt-0.5 h-8 text-sm" data-test="started-input"></label>
                        <label class="mt-1.5 block text-xs text-slate-500">Completed<input type="date" wire:model="completedAt" class="input mt-0.5 h-8 text-sm" data-test="completed-input"></label>
                        @error('startedAt') <p class="field-error">{{ $message }}</p> @enderror
                        @error('completedAt') <p class="field-error">{{ $message }}</p> @enderror
                    </x-slot:editor>
                </x-ui.side-section>

                @if ($canCost)
                    <x-ui.side-section title="Estimate" name="estimate" :panel="$panel" :can="$canEdit">
                        @php $est = $task->effectiveHours() + (float) $subtasks->sum('estimate_hours'); @endphp
                        <p data-test="estimate-line">{{ rtrim(rtrim(number_format($est, 2), '0'), '.') }} h @if ($task->scope->value === 'out') &middot; out of scope @endif</p>
                        @if ($canMoney)
                            @php $over = $est > 0 && $logged > $est; $bad = $est > 0 && $logged > $est * 1.25; @endphp
                            <p @class(['text-xs', 'text-slate-500' => ! $over, 'font-medium text-amber-600 dark:text-amber-400' => $over && ! $bad, 'font-medium text-rose-600 dark:text-rose-400' => $bad]) data-test="logged-vs-estimate">Logged {{ rtrim(rtrim(number_format($logged, 2), '0'), '.') ?: '0' }} h of {{ rtrim(rtrim(number_format($est, 2), '0'), '.') ?: '0' }} h estimate @if ($over) &middot; over by {{ rtrim(rtrim(number_format($logged - $est, 2), '0'), '.') }} h @endif</p>
                            <p class="mt-1" data-test="task-value">
                                @if ($mb->hourly())
                                    {{ \App\Support\Money::format($mb->valueCents($task), $project->currency) }} <span class="text-xs text-slate-500">({{ rtrim(rtrim(number_format($est, 2), '0'), '.') }} h &times; {{ $module->hourly_rate ?? 'no rate' }})</span>
                                @elseif ($task->budget_amount === null)
                                    <span class="text-amber-600 dark:text-amber-400">Unpriced</span>
                                @else
                                    {{ \App\Support\Money::format(\App\Support\Money::toCents((string) $task->budget_amount), $project->currency) }}
                                @endif
                            </p>
                        @endif
                        <x-slot:editor>
                            <div class="space-y-1.5">
                                <select wire:model.live="scope" class="select h-8 text-sm"><option value="in">In scope</option><option value="out">Out of scope (add-on)</option></select>
                                <input type="number" step="0.5" min="0" wire:model="estimate" placeholder="Hours" class="input h-8 text-sm">
                                @error('estimate') <p class="field-error">{{ $message }}</p> @enderror
                                @if ($canMoney && ! $mb->hourly())
                                    <input type="number" step="0.01" min="0" wire:model="budget_amount" placeholder="Value ({{ $project->currency }}), empty = unpriced" aria-label="Budget amount" class="input h-8 text-sm" data-test="amount-input">
                                @elseif ($canMoney)
                                    <p class="text-xs text-slate-500">The value is hours &times; the module's hourly rate.</p>
                                @endif
                                @error('budget_amount') <p class="field-error" data-test="amount-error">{{ $message }}</p> @enderror
                            </div>
                        </x-slot:editor>
                    </x-ui.side-section>
                    @if ($panel === 'estimate')
                        @include('livewire.items._over-budget', ['bv' => $bv])
                    @endif
                @endif

                @if ($canMoney && $task->isAddon())
                    <x-ui.side-section title="Add-on" name="addon">
                        @if ($task->addon_accepted_at)
                            <p data-test="addon-status">Accepted {{ $task->addon_accepted_at->format('M j, Y') }}. It counts in the module budget and the project's add-ons.</p>
                        @else
                            <p class="text-xs text-slate-500" data-test="addon-status">Proposed: not counted in the module's budget until the client confirms.</p>
                            <button type="button" wire:click="acceptAddon" wire:confirm="Has the client confirmed this add-on? Accepting raises {{ $module->name }}'s budget by its value ({{ $mb->format($mb->units($task)) }}) and cannot be undone." class="btn btn-primary mt-2 h-8 px-2.5 py-1 text-xs" data-test="accept-addon">Accept add-on</button>
                            @error('addon') <p class="field-error">{{ $message }}</p> @enderror
                        @endif
                    </x-ui.side-section>
                @endif

                <x-ui.side-section title="Client visible" name="visibility" :panel="$panel" :can="$canEdit">
                    <p>{{ $task->client_visible ? 'Yes, shown in the client portal' : 'No' }}@if ($task->is_idea) &middot; idea / backlog @endif</p>
                    <x-slot:editor>
                        <div class="space-y-1.5">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="clientVisible" class="rounded border-slate-300 text-brand-600"> Visible to the client</label>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isIdea" class="rounded border-slate-300 text-brand-600"> Idea / backlog item</label>
                            <select wire:model="status" class="select h-8 text-sm"><option value="draft">Draft</option><option value="published">Published</option></select>
                        </div>
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Attachments" name="attachments">
                    @forelse ($attachments as $a)
                        <p><a href="{{ $a->url() }}" class="text-brand-600 hover:underline">{{ $a->name }}</a></p>
                    @empty
                        <p class="text-slate-400">None</p>
                    @endforelse
                </x-ui.side-section>

                @include('livewire.items._sidebar-participants')

                @if ($canEdit)
                    <div class="pt-3"><a href="{{ route('tasks.edit', $task) }}" wire:navigate class="text-xs text-slate-500 hover:text-slate-700">Testing add-on and more &rarr;</a></div>
                @endif
            </x-ui.card>
        </aside>
    </div>
</div>
