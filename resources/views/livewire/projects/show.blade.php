<div>
    @php
        $checklist       = app(\App\Services\ProgressService::class)->forProject($project);
        $timeUsed        = $project->timeBar() * 100;
        $estimate        = $project->start_date ? $project->estimatedCompletion() : null;
        $estimateVsActual = $project->estimateVsActual();
    @endphp

    <x-ui.breadcrumb :items="array_filter([
        ['Projects', route('projects.index')],
        [$project->name, $active === 'details' ? null : route('projects.show', $project)],
        $active === 'details' ? null : [$tabs[$active], null],
    ])" />

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
                @can('manage-money')
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Initial budget</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->initial_budget)" :currency="$project->currency" :local="$project->initial_budget_local === null ? null : \App\Support\Money::toCents($project->initial_budget_local)" /></x-ui.card>
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Addons</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->addons_amount)" :currency="$project->currency" :local="$project->addons_amount_local === null ? null : \App\Support\Money::toCents($project->addons_amount_local)" /></x-ui.card>
                <x-ui.card><div class="text-sm text-slate-500 dark:text-gray-400">Final budget</div><x-ui.money-pair class="text-xl font-semibold" :cents="\App\Support\Money::toCents($project->final_budget)" :currency="$project->currency" :local="$project->final_budget_local === null ? null : \App\Support\Money::toCents($project->final_budget_local)" /></x-ui.card>
                @endcan
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
                        <a href="{{ route('modules.create', $project) }}" wire:navigate class="btn btn-primary gap-1" title="Add a module" data-test="add-module"><x-icon name="plus" /> Add module</a>
                    @endif
                </div>

                <div class="mb-4 flex flex-wrap items-center gap-3" data-test="module-filters">
                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 text-sm dark:border-gray-700" role="group" aria-label="Module filter">
                        @foreach (['sprint' => 'In active sprint', 'backlog' => 'Not in sprint', 'completed' => 'Completed', 'all' => 'All'] as $k => $lbl)
                            <button type="button" wire:click="$set('filter', '{{ $k }}')" aria-pressed="{{ $filter === $k ? 'true' : 'false' }}" data-test="filter-{{ $k }}" @class(['px-3 py-1.5 font-medium', 'bg-brand-600 text-white' => $filter === $k, 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300' => $filter !== $k])>{{ $lbl }}</button>
                        @endforeach
                    </div>
                    <select wire:model.live="assignedTo" class="select h-9 w-48 text-sm" aria-label="Assigned to" data-test="filter-user">
                        <option value="">Everyone</option>
                        @foreach ($userOptions as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                </div>

                @php
                    $help = match ($filter) {
                        'sprint' => $activeSprint ? 'Showing modules that have at least one task in '.$activeSprint->name : 'No active sprint — start one in the Sprint tab',
                        'backlog' => 'Showing modules that are not completed and have no task in the active sprint',
                        'completed' => 'Showing completed modules',
                        default => 'Showing all modules; completed ones are in the section at the bottom',
                    };
                    $helpUser = $assignedTo !== '' ? $userOptions->firstWhere('id', (int) $assignedTo) : null;
                @endphp
                <p class="-mt-2 mb-4 text-xs text-slate-500 dark:text-gray-400" data-test="filter-help">{{ $help }}@if ($helpUser). Only modules assigned to {{ $helpUser->name }}, or with a task assigned to them.@endif</p>

                <div x-data x-sortable data-sort-method="reorderModules" data-sort-handle=".module-handle" data-test="module-rows">
                    @foreach ($mainRows as $r)
                        @php($module = $r['m'])
                        @php($mStatus = $r['status'])
                        @include('livewire.projects._module-row')
                    @endforeach
                </div>

                @if ($modules->isEmpty())
                    <x-ui.empty-state title="No modules yet" message="Modules group related tasks — like “Checkout” or “Onboarding”. Add one to start building the plan."></x-ui.empty-state>
                @elseif ($filter === 'sprint' && ! $activeSprint)
                    <x-ui.empty-state title="No active sprint" message="Start a sprint in the Sprint tab to see which modules are part of it." data-test="module-no-sprint"></x-ui.empty-state>
                @elseif ($mainRows->isEmpty() && $doneRows->isEmpty())
                    <x-ui.empty-state title="No modules match" message="Nothing fits this filter. Try another one, or pick Everyone." data-test="module-empty"></x-ui.empty-state>
                @endif

                @if ($doneRows->isNotEmpty())
                    <div x-data="{ done: false }" class="mt-2" data-test="completed-section">
                        <button type="button" @click="done = !done" class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-green-700 dark:text-green-400" :aria-expanded="done" data-test="completed-toggle">
                            <x-icon name="chevron-right" class="h-4 w-4 transition" ::class="done && 'rotate-90'" /> Completed ({{ $doneRows->count() }})
                        </button>
                        <div x-show="done" style="display:none" data-test="completed-rows">
                            @foreach ($doneRows as $r)
                                @php($module = $r['m'])
                                @php($mStatus = $r['status'])
                                @include('livewire.projects._module-row')
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
    </div>
@elseif ($active === 'sprint')
    <div class="mt-6"><livewire:sprints.panel :project="$project" :key="'sprints-'.$project->id" /></div>
@elseif ($active === 'resources')
    <div class="mt-6"><livewire:resources.panel :project="$project" /></div>
@elseif ($active === 'board')
    <div class="mt-6"><livewire:tasks.board :project="$project" :embedded="true" :key="'board-'.$project->id" /></div>
@elseif ($active === 'economics')
    <div class="mt-6"><livewire:projects.economics :project="$project" :embedded="true" :key="'econ-'.$project->id" /></div>
@endif
</div>
