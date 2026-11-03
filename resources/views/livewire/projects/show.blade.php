<div>
    @php
        $checklist       = app(\App\Services\ProgressService::class)->forProject($project);
        $timeUsed        = $project->timeBar() * 100;
        $estimate        = $project->start_date ? $project->estimatedCompletion() : null;
        $estimateVsActual = $project->estimateVsActual();
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
            <p class="mt-1 text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->currency }}</p>
        </div>
        <div class="flex items-center gap-2">
            @unless (auth()->user()->hasRole('client'))
                <a href="{{ route('projects.edit', $project) }}" wire:navigate class="btn btn-secondary">Edit project</a>
            @endunless
        </div>
    </div>

    @if ($project->description)
        <x-ui.markdown :text="$project->description" class="mt-4 max-w-2xl" />
    @endif
    @if ($project->aim)
        <div class="mt-3 max-w-2xl"><p class="text-sm font-semibold text-slate-900">Aim</p><x-ui.markdown :text="$project->aim" /></div>
    @endif

    <div class="mt-6 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $active === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $active !== $key])>{{ $label }}</button>
        @endforeach
    </div>

@if ($active === 'details')
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Initial budget</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->initial_budget)" :currency="$project->currency" :local="$project->initial_budget_local === null ? null : \App\Support\Money::toCents($project->initial_budget_local)" /></x-ui.card>
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Addons</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->addons_amount)" :currency="$project->currency" :local="$project->addons_amount_local === null ? null : \App\Support\Money::toCents($project->addons_amount_local)" /></x-ui.card>
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Final budget</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->final_budget)" :currency="$project->currency" :local="$project->final_budget_local === null ? null : \App\Support\Money::toCents($project->final_budget_local)" /></x-ui.card>
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Modules</div><div class="text-xl font-semibold">{{ $modules->count() }}</div></x-ui.card>
            </div>

            @php $dStatus = $project->deadlineStatus(); @endphp
            <x-ui.card>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Deadline</h2>
                    @if ($dStatus) <x-ui.badge :color="$dStatus[1]">{{ $dStatus[0] }}</x-ui.badge> @endif
                </div>
                @if ($project->deadline)
                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-slate-500">Deadline</dt>
                            <dd class="text-xl font-semibold text-slate-900">{{ $project->deadline->format('M j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ $project->status === 'completed' ? 'Completed' : 'Days left' }}</dt>
                            <dd class="text-xl font-semibold text-slate-900">
                                @if ($project->status === 'completed') {{ $project->completed_at?->format('M j, Y') ?? '—' }}
                                @else
                                    @php $left = (int) today()->diffInDays($project->deadline, false); @endphp
                                    {{ $left >= 0 ? $left : abs($left).' overdue' }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Estimated completion</dt>
                            <dd class="text-xl font-semibold text-slate-900">{{ $estimate?->format('M j, Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-slate-500">No deadline set.</p>
                @endif

                @if ($canManage)
                    <form wire:submit="changeDeadline" class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:grid-cols-[auto_1fr_auto]">
                        <input type="date" wire:model="newDeadline" class="input" aria-label="New deadline">
                        <input type="text" wire:model="deadlineReason" placeholder="{{ $project->deadline ? 'Reason (required)' : 'Reason (optional)' }}" class="input" aria-label="Reason">
                        <button type="submit" class="btn btn-secondary">{{ $project->deadline ? 'Change deadline' : 'Set deadline' }}</button>
                        @error('newDeadline') <p class="field-error sm:col-span-3">{{ $message }}</p> @enderror
                        @error('deadlineReason') <p class="field-error sm:col-span-3">{{ $message }}</p> @enderror
                    </form>
                @endif

                @php $changes = $project->deadlineChanges()->with('user')->get(); @endphp
                @if ($changes->isNotEmpty())
                    <p class="mt-4 text-sm font-medium text-slate-900">Extended {{ $project->extensionCount() }} {{ Str::plural('time', $project->extensionCount()) }}</p>
                    <ul class="mt-2 divide-y divide-slate-100 text-sm">
                        @foreach ($changes as $c)
                            <li class="py-2">
                                <div class="flex justify-between gap-3">
                                    <span class="text-slate-700">
                                        {{ $c->old_deadline ? $c->old_deadline->format('M j, Y').' → ' : 'Set to ' }}{{ $c->new_deadline->format('M j, Y') }}
                                        @if ($c->old_deadline) @php $d = (int) $c->old_deadline->diffInDays($c->new_deadline, false); @endphp <span class="{{ $d > 0 ? 'text-rose-600' : 'text-green-600' }}">({{ $d > 0 ? '+' : '' }}{{ $d }}d)</span> @endif
                                    </span>
                                    <span class="text-slate-400">{{ $c->user->name }} &middot; {{ $c->created_at->format('M j, Y') }}</span>
                                </div>
                                @if ($c->reason) <p class="text-slate-500">{{ $c->reason }}</p> @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-4 text-sm font-semibold text-slate-900">Project health</h2>
                <x-ui.health-meter :checklist="$checklist" :time="$timeUsed" />
            </x-ui.card>
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
                        <dd class="font-medium text-slate-900">{{ $estimate?->format('M j, Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Buffer</dt>
                        <dd class="font-medium text-slate-900">{{ rtrim(rtrim(number_format((float) $project->buffer_percent, 2), '0'), '.') }}%</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Estimated hours</dt>
                        <dd class="font-medium text-slate-900">{{ number_format($estimateVsActual['estimated'], 1) }}h</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Logged hours</dt>
                        <dd class="font-medium text-slate-900">{{ number_format($estimateVsActual['logged'], 1) }}h</dd>
                    </div>
                </dl>
            </x-ui.card>

            @can('manage-money')
                @php $money = \App\Services\ProjectEconomics::money($project); $cur = $project->currency; @endphp
                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Money</h2>
                        <a href="{{ route('projects.economics', $project) }}" wire:navigate class="link text-xs font-medium">Economics</a>
                    </div>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-gray-400">Invoiced</dt>
                            <dd class="text-right font-medium text-slate-900 dark:text-white"><x-ui.money-pair :cents="$money['invoiced_cents']" :currency="$cur" :local="$money['invoiced_local']" :missing="$money['invoiced_missing_rate']" /></dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-gray-400">Received</dt>
                            <dd class="text-right font-medium text-slate-900 dark:text-white"><x-ui.money-pair :cents="$money['received_cents']" :currency="$cur" :local="$money['received_local']" :missing="$money['received_missing_rate']" /></dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endcan

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
@elseif ($active === 'modules')
    <div class="mt-6">
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-display text-lg font-semibold text-slate-900">Modules & tasks</h2>
                    @if ($canManage)
                        <a href="{{ route('modules.create', $project) }}" wire:navigate class="btn btn-primary" data-test="add-module">+ Add module</a>
                    @endif
                </div>

                @forelse ($modules as $module)
                    @php
                        $mStatus = $module->status();
                        $mPct = round($module->progress() * 100);
                        $mOverdue = $module->deadline && $module->deadline->isPast() && $mStatus !== 'completed';
                    @endphp
                    <x-ui.card class="mb-4" padding="p-0" data-test="module-row" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                            <button type="button" @click="open = !open" class="shrink-0 text-slate-400 hover:text-slate-600" aria-label="Expand module" data-test="module-toggle">
                                <svg class="h-4 w-4 transition" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                            </button>
                            <h3 class="min-w-0 flex-1 basis-40 truncate font-display text-sm font-semibold text-slate-900"><a href="{{ route('modules.show', [$project, $module]) }}" wire:navigate class="hover:text-brand-600">{{ $module->name }}</a></h3>
                            <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$mStatus]" data-test="module-status">{{ \App\Models\Module::STATUS_LABELS[$mStatus] }}</x-ui.badge>
                            <span @class(['w-24 text-xs', 'font-medium text-rose-600' => $mOverdue, 'text-slate-500' => ! $mOverdue]) data-test="module-deadline">{{ $module->deadline ? $module->deadline->format('M j, Y') : 'No deadline' }}</span>
                            <span class="flex -space-x-1">
                                @foreach ($module->assignees as $u)
                                    <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700 ring-2 ring-white">{{ collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                                @endforeach
                            </span>
                            <span class="flex flex-wrap gap-1">
                                @foreach ($module->labels as $l)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                                @endforeach
                            </span>
                            <div class="flex w-40 items-center gap-2" title="{{ $mPct }}% of the work done">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-500" style="width: {{ $mPct }}%"></div></div>
                                <span class="w-9 text-right text-xs font-semibold tabular-nums text-slate-600" data-test="module-progress">{{ $mPct }}%</span>
                            </div>
                            <span class="w-14 text-right text-xs text-slate-400" data-test="module-task-count">{{ $module->tasks->count() }} {{ Str::plural('task', $module->tasks->count()) }}</span>
                            @can('manage-money')
                                <span class="text-xs text-slate-500">{{ $module->budgetAmount() === null ? 'No budget' : \App\Support\Money::format(\App\Support\Money::toCents($module->budgetAmount()), $project->currency) }}</span>
                            @endcan
                        </div>
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
                                @php $hourly = $project->client->billing_mode === \App\Enums\BillingMode::Hourly; @endphp
                                <div class="mt-2 text-xs text-slate-500" x-data="{ editingBudget: false }">
                                    <div x-show="!editingBudget" class="flex items-center gap-2">
                                        <span>Budget: {{ $module->budgetAmount() === null ? 'not set' : \App\Support\Money::format(\App\Support\Money::toCents($module->budgetAmount()), $project->currency) }}@if ($module->deadline) &middot; due {{ $module->deadline->toDateString() }}@endif</span>
                                        <button @click="editingBudget = true" class="shrink-0 font-medium text-brand-600 hover:text-brand-700">Edit budget</button>
                                    </div>
                                    <form x-show="editingBudget" style="display:none" wire:submit.prevent="saveBudget({{ $module->id }})" @submit="editingBudget = false" class="space-y-1.5">
                                        @if ($hourly)
                                            <input type="number" step="0.01" min="0" wire:model="budget.{{ $module->id }}.hours" placeholder="Budget hours" class="input h-8 text-xs">
                                            <input type="text" wire:model="budget.{{ $module->id }}.rate" placeholder="Hourly rate ({{ $project->currency }})" class="input h-8 text-xs">
                                        @else
                                            <input type="text" wire:model="budget.{{ $module->id }}.amount" placeholder="Fixed amount ({{ $project->currency }})" class="input h-8 text-xs">
                                        @endif
                                        <input type="date" wire:model="budget.{{ $module->id }}.deadline" class="input h-8 text-xs">
                                        <input type="text" wire:model="budget.{{ $module->id }}.reason" placeholder="Reason (required when changing an existing value)" class="input h-8 text-xs">
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

                        <ul class="divide-y divide-slate-100">
                            @foreach ($module->tasks as $task)
                                @php $taskProgress = $progress->forTask($task); @endphp
                                <li x-data="{ open: false }" class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <button @click="open = !open" class="flex shrink-0 items-center text-left">
                                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                        </button>
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate data-test="task-link" class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 hover:text-brand-600">{{ $task->title }}</a>
                                        <span class="flex -space-x-1">
                                            @foreach ($task->assignees as $u)
                                                <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700 ring-2 ring-white">{{ collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                                            @endforeach
                                        </span>
                                        @foreach ($task->labels as $l)
                                            <span class="hidden items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200 sm:inline-flex"><span class="h-2 w-2 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}</span>
                                        @endforeach
                                        @if ($task->deadline)
                                            <span @class(['text-xs', 'font-medium text-rose-600' => $task->deadline->isPast() && $task->column?->stage !== 'done', 'text-slate-500' => ! ($task->deadline->isPast() && $task->column?->stage !== 'done')])>{{ $task->deadline->format('M j') }}</span>
                                        @endif
                                        <x-ui.badge color="slate">{{ $task->column?->name }}@if ($task->column) <span class="text-slate-400">&middot; {{ str_replace('_', ' ', $task->column->stage) }}</span>@endif</x-ui.badge>
                                        <div class="flex w-40 items-center gap-2" title="{{ round($taskProgress) }}% done" data-test="task-progress-bar">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-500" style="width: {{ round($taskProgress) }}%"></div></div>
                                            <span class="w-9 text-right text-xs font-semibold tabular-nums text-slate-600" data-test="task-progress">{{ round($taskProgress) }}%</span>
                                        </div>
                                        @if($canManage)
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700">Edit</a>
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
                                <button type="submit" class="btn btn-secondary h-9 whitespace-nowrap text-sm">+ Task</button>
                            </form>
                        @endif
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.empty-state title="No modules yet" message="Modules group related tasks — like “Checkout” or “Onboarding”. Add one to start building the plan.">
                    </x-ui.empty-state>
                @endforelse
            </div>
    </div>
@elseif ($active === 'resources')
    <div class="mt-6"><livewire:resources.panel :project="$project" /></div>
@elseif ($active === 'board')
    <div class="mt-6"><livewire:tasks.board :project="$project" :key="'board-'.$project->id" /></div>
@elseif ($active === 'economics')
    <div class="mt-6"><livewire:projects.economics :project="$project" :key="'econ-'.$project->id" /></div>
@endif
</div>
