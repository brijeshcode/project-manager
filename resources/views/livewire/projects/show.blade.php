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

    <div class="mt-6 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $active === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $active !== $key])>{{ $label }}</button>
        @endforeach
    </div>

@if ($active === 'details')
    @php
        $waiting = $modules->where('request_status', 'pending')->count() + $modules->flatMap->tasks->where('request_status', 'pending')->count();
        $changes = $project->deadlineChanges()->with('user')->get();
    @endphp

    @if ($waiting)
        <a href="{{ route('projects.show', [$project, 'tab' => 'modules']) }}" wire:navigate class="mt-6 flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" data-test="requests-waiting">
            <x-icon name="triangle-alert" class="h-4 w-4 shrink-0" />
            <span class="flex-1"><span class="font-semibold">{{ $waiting }} client {{ Str::plural('request', $waiting) }} waiting for approval</span></span>
            <span class="font-medium">Review &rarr;</span>
        </a>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">About this project</h2>
                @if ($project->aim)
                    <div class="mb-4 flex gap-3 rounded-lg border-l-4 border-brand-500 bg-brand-50/60 px-4 py-3 dark:bg-brand-500/10">
                        <x-icon name="flag" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700 dark:text-brand-300">Aim</p>
                            <x-ui.markdown :text="$project->aim" class="text-slate-800 dark:text-gray-200" />
                        </div>
                    </div>
                @endif
                @if ($project->description)
                    <x-ui.markdown :text="$project->description" class="max-w-none" />
                @endif
                @if (! $project->aim && ! $project->description)
                    <p class="text-sm text-slate-500 dark:text-gray-400">No aim or description yet. @if ($canManage)<a href="{{ route('projects.edit', $project) }}" wire:navigate class="link">Add them</a> so the team and the client know what this project is for.@endif</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Progress</h2>
                @include('livewire.projects._project-counts', ['countModules' => $modules->whereNull('request_status'), 'countTasks' => \App\Models\Task::counted()->whereIn('module_id', $modules->pluck('id'))->with('column')->get()])
                <div class="mt-5 border-t border-slate-100 pt-4 dark:border-gray-700">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">Project health</h3>
                    <x-ui.health-meter :checklist="$checklist" :time="$timeUsed" />
                </div>
            </x-ui.card>

            @can('manage-money')
                @php
                    $money = \App\Services\ProjectEconomics::money($project);
                    $cur = $project->currency;
                    $finalCents = \App\Support\Money::toCents($project->final_budget);
                    $allocated = $project->allocatedToModules();
                @endphp
                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Budget</h2>
                        <a href="{{ route('projects.economics', $project) }}" wire:navigate class="link text-xs font-medium">Economics &rarr;</a>
                    </div>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:divide-x sm:divide-slate-100 dark:sm:divide-gray-700">
                        @foreach ([['Initial budget', $project->initial_budget, $project->initial_budget_local], ['Addons', $project->addons_amount, $project->addons_amount_local], ['Final budget', $project->final_budget, $project->final_budget_local]] as $i => [$lbl, $amt, $loc])
                            <div @class(['sm:pl-4' => $i > 0])>
                                <dt class="text-xs font-medium text-slate-500 dark:text-gray-400">{{ $lbl }}</dt>
                                <dd class="mt-1"><x-ui.money-pair @class(['text-lg font-semibold', 'text-brand-700 dark:text-brand-300' => $i === 2]) :cents="\App\Support\Money::toCents($amt)" :currency="$cur" :local="$loc === null ? null : \App\Support\Money::toCents($loc)" /></dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-5">
                        <p class="mb-1.5 text-xs text-slate-500 dark:text-gray-400" data-test="allocated-line">Allocated to modules: {{ \App\Support\Money::format($allocated, $cur) }} of Final {{ \App\Support\Money::format($finalCents, $cur) }}</p>
                        <x-ui.progress :pct="$finalCents > 0 ? min(100, $allocated / $finalCents * 100) : 0" />
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 text-sm dark:border-gray-700">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-gray-400">Invoiced</dt>
                            <dd class="mt-1 font-semibold text-slate-900 dark:text-white"><x-ui.money-pair :cents="$money['invoiced_cents']" :currency="$cur" :local="$money['invoiced_local']" :missing="$money['invoiced_missing_rate']" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-gray-400">Received</dt>
                            <dd class="mt-1 font-semibold text-slate-900 dark:text-white"><x-ui.money-pair :cents="$money['received_cents']" :currency="$cur" :local="$money['received_local']" :missing="$money['received_missing_rate']" /></dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endcan

            @if ($canManage || $changes->isNotEmpty())
                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Deadline changes</h2>
                        @if ($changes->isNotEmpty())<span class="text-xs text-slate-500 dark:text-gray-400">Extended {{ $project->extensionCount() }} {{ Str::plural('time', $project->extensionCount()) }}</span>@endif
                    </div>
                    @if ($canManage)
                        <form wire:submit="changeDeadline" class="grid gap-2 sm:grid-cols-[auto_1fr_auto]">
                            <input type="date" wire:model="newDeadline" class="input" aria-label="New deadline">
                            <input type="text" wire:model="deadlineReason" placeholder="{{ $project->deadline ? 'Reason (required)' : 'Reason (optional)' }}" class="input" aria-label="Reason">
                            <button type="submit" class="btn btn-secondary">{{ $project->deadline ? 'Change deadline' : 'Set deadline' }}</button>
                            @error('newDeadline') <p class="field-error sm:col-span-3">{{ $message }}</p> @enderror
                            @error('deadlineReason') <p class="field-error sm:col-span-3">{{ $message }}</p> @enderror
                        </form>
                    @endif
                    @if ($changes->isNotEmpty())
                        <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-gray-700">
                            @foreach ($changes as $c)
                                <li class="py-2">
                                    <div class="flex justify-between gap-3">
                                        <span class="text-slate-700 dark:text-gray-200">
                                            {{ $c->old_deadline ? $c->old_deadline->format('M j, Y').' → ' : 'Set to ' }}{{ $c->new_deadline->format('M j, Y') }}
                                            @if ($c->old_deadline) @php $d = (int) $c->old_deadline->diffInDays($c->new_deadline, false); @endphp <span class="{{ $d > 0 ? 'text-rose-600' : 'text-green-600' }}">({{ $d > 0 ? '+' : '' }}{{ $d }}d)</span> @endif
                                        </span>
                                        <span class="shrink-0 text-slate-400">{{ $c->user->name }} &middot; {{ $c->created_at->format('M j, Y') }}</span>
                                    </div>
                                    @if ($c->reason) <p class="text-slate-500 dark:text-gray-400">{{ $c->reason }}</p> @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            @include('livewire.projects._timeline')

            <x-ui.card>
                <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Effort</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-gray-400">Estimated hours</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ number_format($estimateVsActual['estimated'], 1) }}h</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-gray-400">Logged hours</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ number_format($estimateVsActual['logged'], 1) }}h</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Team</h2>
                    <span class="text-xs text-slate-500 dark:text-gray-400">{{ $project->members->count() }}</span>
                </div>
                @if ($project->members->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-gray-400">No members assigned yet.</p>
                @else
                    <ul class="space-y-2.5">
                        @foreach ($project->members as $member)
                            @php $mi = collect(explode(' ', trim($member->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode(''); @endphp
                            <li class="flex items-center gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $mi }}</span>
                                <span class="text-sm font-medium text-slate-800 dark:text-gray-200">{{ $member->name }}</span>
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

                @include('livewire.projects._module-list')
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
