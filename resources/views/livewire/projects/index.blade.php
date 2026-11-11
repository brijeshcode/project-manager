<div>
    <x-ui.page-header title="Projects" subtitle="Every project across your clients, with live progress.">
        @unless (auth()->user()->hasRole('client'))
            <x-slot name="actions">
                <a href="{{ route('projects.create') }}" wire:navigate class="btn btn-primary gap-1"><x-icon name="plus" /> New project</a>
            </x-slot>
        @endunless
    </x-ui.page-header>

    @php
        $statuses = ['active' => 'Active', 'on_hold' => 'On hold', 'draft' => 'Draft', 'completed' => 'Completed'];
        $statusColor = ['active' => 'green', 'on_hold' => 'amber', 'draft' => 'slate', 'completed' => 'brand'];
        $canMoney = auth()->user()->can('manage-money');
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex max-w-full overflow-x-auto rounded-lg border border-slate-200 text-sm dark:border-gray-700" role="group" aria-label="Status filter" data-test="status-pills">
            @foreach (['' => 'All'] + $statuses as $key => $label)
                @php $n = $key === '' ? $statusCounts->sum() : ($statusCounts[$key] ?? 0); @endphp
                <button type="button" wire:click="$set('status', '{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" data-test="status-{{ $key ?: 'all' }}"
                    @class(['flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 font-medium', 'bg-brand-600 text-white' => $status === $key, 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300 dark:hover:bg-gray-800' => $status !== $key])>
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-white/20' => $status === $key, 'bg-slate-100 text-slate-500 dark:bg-gray-700 dark:text-gray-400' => $status !== $key])>{{ $n }}</span>
                </button>
            @endforeach
        </div>
        <div class="relative sm:w-72">
            <label for="search" class="sr-only">Search projects</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search project or client…" class="input pl-9" />
        </div>
    </div>

    @if ($projects->isEmpty() && ($search !== '' || $status !== ''))
        <x-ui.empty-state title="No matches" message="No projects match your filters.">
            <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
        </x-ui.empty-state>
    @elseif ($projects->isEmpty())
        <x-ui.empty-state title="No projects yet" message="Projects group the modules, tasks, and time for one piece of client work.">
            @unless (auth()->user()->hasRole('client'))
                <x-slot name="action">
                    <a href="{{ route('projects.create') }}" wire:navigate class="btn btn-primary">Create a project</a>
                </x-slot>
            @endunless
        </x-ui.empty-state>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                @php
                    $pct = (int) round(app(\App\Services\ProgressService::class)->forProject($project));
                    $timeUsed = (int) round($project->timeBar() * 100);
                    $ds = $project->deadlineStatus();
                    $left = $project->deadline && $project->status !== 'completed' ? (int) today()->diffInDays($project->deadline, false) : null;
                    $members = $project->members;
                @endphp
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}" data-test="project-card"
                   class="card group flex flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-card-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-gray-500">{{ $project->client->name }}</p>
                            <h3 class="mt-0.5 truncate font-display text-base font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white">{{ $project->name }}</h3>
                        </div>
                        <x-ui.badge :color="$statusColor[$project->status] ?? 'slate'">{{ $statuses[$project->status] ?? ucfirst($project->status) }}</x-ui.badge>
                    </div>

                    <div class="mt-5">
                        <div class="mb-1.5 flex items-baseline justify-between">
                            <span class="text-xs font-medium text-slate-500 dark:text-gray-400">Progress</span>
                            <span class="font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $pct }}%</span>
                        </div>
                        <x-ui.progress :pct="$pct" :label="false" />
                        <div class="mt-1.5 flex justify-between text-xs text-slate-500 dark:text-gray-400">
                            <span>{{ $project->modules_count }} {{ Str::plural('module', $project->modules_count) }}</span>
                            <span @class(['text-rose-600 font-medium' => $timeUsed > 100])>{{ $timeUsed }}% of estimated time</span>
                        </div>
                    </div>

                    <div class="mt-5 flex items-end justify-between gap-3 border-t border-slate-100 pt-4 dark:border-gray-700">
                        <div class="min-w-0 text-xs">
                            @if ($project->deadline)
                                <p class="flex items-center gap-1 text-slate-500 dark:text-gray-400"><x-icon name="calendar" class="h-3.5 w-3.5" /> Due {{ $project->deadline->format('M j, Y') }}</p>
                                @if ($left !== null)
                                    <p @class(['mt-0.5 font-semibold', 'text-rose-600' => $left < 0, 'text-amber-600' => $left >= 0 && $ds && $ds[1] !== 'green', 'text-slate-700 dark:text-gray-200' => $left >= 0 && (! $ds || $ds[1] === 'green')])>{{ $left >= 0 ? $left.' '.Str::plural('day', $left).' left' : abs($left).' '.Str::plural('day', abs($left)).' overdue' }}</p>
                                @elseif ($ds)
                                    <p class="mt-0.5 font-semibold text-slate-700 dark:text-gray-200">{{ Str::before($ds[0], ' —') }}</p>
                                @endif
                            @else
                                <p class="text-slate-400 dark:text-gray-500">No deadline</p>
                            @endif
                            @if ($canMoney && $project->final_budget !== null)
                                <p class="mt-1 font-medium text-slate-700 dark:text-gray-200" data-test="card-budget">{{ \App\Support\Money::format(\App\Support\Money::toCents($project->final_budget), $project->currency) }}</p>
                            @endif
                        </div>
                        @if ($members->isNotEmpty())
                            <div class="flex shrink-0 -space-x-1.5">
                                @foreach ($members->take(4) as $m)<x-ui.avatar :user="$m" />@endforeach
                                @if ($members->count() > 4)<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-800">+{{ $members->count() - 4 }}</span>@endif
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-6">{{ $projects->links() }}</div>
    @endif
</div>
