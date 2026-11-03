<div>
    @php
        $openTasks = $module->openTaskCount();
        $money = auth()->user()->can('manage-money');
        $canEdit = $canManage;
        $initials = fn ($u) => collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    @endphp

    <x-ui.breadcrumb :items="[['Projects', route('projects.index')], [$project->name, route('projects.show', ['project' => $project, 'tab' => 'modules'])], [$module->name, null]]" />

    {{-- Header --}}
    <div class="flex flex-col gap-3">
        @include('livewire.items._title', ['heading' => $module->name, 'canEdit' => $canManage])
        <div class="flex flex-wrap items-center gap-2 pl-0 sm:pl-7">
            <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$status]" data-test="status-badge">{{ \App\Models\Module::STATUS_LABELS[$status] }}</x-ui.badge>
            <span class="text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->name }}</span>
            @if ($canManage)
                <span class="ml-auto flex flex-wrap items-center gap-2">
                    @if ($module->status_override)
                        <button wire:click="reopen" class="btn btn-secondary h-8 px-3 py-1 text-xs">Reopen</button>
                    @else
                        <button wire:click="putOnHold" class="btn btn-secondary h-8 px-3 py-1 text-xs">On hold</button>
                    @endif
                    @if ($module->status_override !== 'completed')
                        @if ($openTasks > 0)
                            <button wire:click="markCompleted" wire:confirm="{{ $openTasks }} {{ Str::plural('task', $openTasks) }} still open. Mark this module completed anyway?" class="btn btn-secondary h-8 px-3 py-1 text-xs">Mark completed</button>
                        @else
                            <button wire:click="markCompleted" class="btn btn-secondary h-8 px-3 py-1 text-xs">Mark completed</button>
                        @endif
                    @endif
                    <a href="{{ $copyUrl }}" wire:navigate class="btn btn-secondary h-8 gap-1 px-3 py-1 text-xs" title="Copy this module" data-test="copy"><x-icon name="copy" /> Copy</a>
                </span>
            @endif
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @include('livewire.items._description', ['text' => $module->description, 'canEdit' => $canManage])

            {{-- Task list, right below the description --}}
            <x-ui.card padding="p-0" data-test="task-list">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="font-display text-sm font-semibold text-slate-900">Tasks <span class="font-normal text-slate-400">({{ $tasks->count() }})</span></h2>
                    @if ($canManage)
                        <a href="{{ route('tasks.create', ['module' => $module->id]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700" data-test="add-task"><x-icon name="plus" /> Add task</a>
                    @endif
                </div>
                <ul class="divide-y divide-slate-100" x-data x-sortable data-sort-method="reorderTasks" data-test="task-rows">
                    @forelse ($tasks as $task)
                        @php $done = $task->column?->stage === 'done'; @endphp
                        <li wire:key="task-{{ $task->id }}" data-id="{{ $task->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 bg-white px-3 py-3 dark:bg-transparent" data-test="task-row">
                            @if ($canManage)
                                <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                            @endif
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 flex-1 basis-40 truncate text-sm font-medium text-slate-800 hover:text-brand-600">{{ $task->title }}</a>
                            <x-ui.priority :value="$task->priority" />
                            <span class="flex -space-x-1">
                                @foreach ($task->assignees as $u)
                                    <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700 ring-2 ring-white">{{ $initials($u) }}</span>
                                @endforeach
                            </span>
                            @foreach ($task->labels as $l)
                                <span class="hidden items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200 sm:inline-flex"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                            @endforeach
                            <x-ui.deadline :date="$task->deadline" :done="$done" class="text-xs" />
                            <x-ui.stage-badge :column="$task->column" />
                            <x-ui.progress :pct="$progressService->forTask($task)" class="w-32" data-test="task-bar" />
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm text-slate-400">No tasks in this module yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            {{-- Module progress --}}
            <x-ui.card data-test="module-progress">
                <h2 class="mb-2 font-display text-sm font-semibold text-slate-900">Progress</h2>
                <x-ui.progress :pct="$pct" data-test="module-bar" />
            </x-ui.card>

            {{-- Timeline --}}
            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                @include('livewire.items._timeline')
            </div>
        </div>

        {{-- Sidebar --}}
        <aside>
            <x-ui.card class="text-sm" padding="p-4">
                @include('livewire.items._sidebar-people', ['canEdit' => $canManage])

                <x-ui.side-section title="Deadline" name="deadline" :panel="$panel" :can="$canManage">
                    @if ($module->deadline)
                        <x-ui.deadline :date="$module->deadline" :done="$status === 'completed'" format="M j, Y" data-test="deadline" />
                    @else
                        <p class="text-slate-400">Not set</p>
                    @endif
                    <x-slot:editor>
                        <input type="date" wire:model="deadline" class="input h-8 text-sm">
                        @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
                        <input type="text" wire:model="deadlineReason" placeholder="Reason (optional)" class="input mt-1.5 h-8 text-sm">
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Dates" name="dates">
                    <dl class="space-y-0.5" data-test="dates">
                        <div class="flex justify-between"><dt class="text-slate-500">Started</dt><dd data-test="module-started">{{ $startedAt?->format('M j, Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Completed</dt><dd data-test="module-ended">{{ $endedAt?->format('M j, Y') ?? '—' }}</dd></div>
                    </dl>
                </x-ui.side-section>

                <x-ui.side-section title="Client visible" name="visibility" :panel="$panel" :can="$canManage">
                    <p>{{ $module->client_visible ? 'Yes, shown in the client portal' : 'No' }}</p>
                    <x-slot:editor>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="clientVisible" class="rounded border-slate-300 text-brand-600"> Visible to the client in their portal</label>
                    </x-slot:editor>
                </x-ui.side-section>

                @if ($money)
                    <x-ui.side-section title="Budget" name="budget" :panel="$panel" :can="$canManage">
                        @if ($module->budgetAmount() === null)
                            <p class="text-slate-400">Not set</p>
                        @else
                            <p>{{ \App\Support\Money::format(\App\Support\Money::toCents($module->budgetAmount()), $project->currency) }}</p>
                            @if ($hourly && $module->budget_hours !== null)
                                <p class="text-xs text-slate-500">{{ $module->budget_hours }} h @ {{ $module->hourly_rate }}</p>
                            @endif
                        @endif
                        <x-slot:editor>
                            <div class="space-y-1.5">
                                @if ($hourly)
                                    <input type="number" step="0.01" min="0" wire:model="hours" placeholder="Budget hours" class="input h-8 text-sm">
                                    <input type="text" wire:model="rate" placeholder="Hourly rate ({{ $project->currency }})" class="input h-8 text-sm">
                                @else
                                    <input type="text" wire:model="amount" placeholder="Fixed amount ({{ $project->currency }})" class="input h-8 text-sm">
                                @endif
                                <input type="text" wire:model="budgetReason" placeholder="Reason (required when changing a value)" class="input h-8 text-sm">
                            </div>
                            @foreach (['hours', 'rate', 'amount', 'budgetReason'] as $f)
                                @error($f) <p class="field-error">{{ $message }}</p> @enderror
                            @endforeach
                        </x-slot:editor>
                    </x-ui.side-section>
                @endif

                @include('livewire.items._sidebar-participants')
            </x-ui.card>
        </aside>
    </div>
</div>
