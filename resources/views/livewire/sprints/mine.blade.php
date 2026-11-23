<div data-test="my-sprint">
    @php $fmt = fn ($h) => rtrim(rtrim(number_format((float) $h, 1), '0'), '.').'h'; @endphp
    <x-ui.page-header title="My sprint" subtitle="Your work across every project's active sprint.">
        <x-slot name="actions">
            <div class="inline-flex rounded-lg border border-slate-200 text-sm dark:border-gray-700" role="group" aria-label="Show" data-test="show-toggle">
                @foreach (['mine' => 'Mine', 'all' => 'All tasks'] as $key => $label)
                    <button type="button" wire:click="$set('show', '{{ $key }}')" aria-pressed="{{ $show === $key ? 'true' : 'false' }}" data-test="show-{{ $key }}"
                        @class(['px-3 py-1.5 font-medium first:rounded-l-lg last:rounded-r-lg', 'bg-brand-600 text-white' => $show === $key, 'text-slate-600 hover:bg-slate-50 dark:text-gray-300 dark:hover:bg-gray-700' => $show !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot>
    </x-ui.page-header>

    @if (! $hasSprints)
        <x-ui.empty-state title="No active sprints" message="None of your projects has a sprint running right now." data-test="no-sprints" />
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <x-ui.stat label="Open tasks" :value="$open" icon="document" color="blue" compact />
            <x-ui.stat label="Done this sprint" :value="$done" icon="check" color="green" compact />
            <div class="card px-3 py-2" data-test="capacity">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sprint load</p>
                @if ($load['capacity'] === null)
                    <p class="mt-1 text-sm text-slate-700 dark:text-gray-200">{{ $fmt($load['committed']) }} committed · <a href="{{ route('profile') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700" data-test="set-capacity">Set your weekly capacity</a></p>
                @else
                    @php $pct = $load['capacity'] > 0 ? min(100, $load['committed'] / $load['capacity'] * 100) : 100; @endphp
                    <p @class(['mt-1 text-sm font-semibold tabular-nums', 'text-amber-600 dark:text-amber-400' => $load['over'], 'text-slate-800 dark:text-gray-100' => ! $load['over']])>{{ $fmt($load['committed']) }} / {{ $fmt($load['capacity']) }}@if ($load['over']) <span class="text-xs font-medium">over capacity</span>@endif</p>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-gray-700"><div @class(['h-full rounded-full', 'bg-amber-500' => $load['over'], 'bg-blue-500' => ! $load['over']]) style="width: {{ $pct }}%" data-test="capacity-bar"></div></div>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            @forelse ($groups as $g)
                @php $s = $g['sprint']; $days = $s->daysLeft(); @endphp
                <x-ui.card padding="p-0" wire:key="sprint-{{ $s->id }}" data-test="sprint-group">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-slate-100 px-4 py-3 dark:border-gray-700">
                        <a href="{{ route('projects.show', $s->project) }}" wire:navigate class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white" data-test="group-project">{{ $s->project->name }}</a>
                        <a href="{{ route('projects.show', [$s->project, 'tab' => 'board', 'scope' => 'sprint']) }}" wire:navigate class="text-sm text-slate-500 hover:text-brand-600 dark:text-gray-400">{{ $s->name }}</a>
                        <span @class(['text-xs font-medium', 'text-red-600 dark:text-red-400' => $days < 0, 'text-slate-500 dark:text-gray-400' => $days >= 0]) data-test="days-left">{{ $days < 0 ? abs($days).'d overdue' : ($days === 0 ? 'Ends today' : $days.'d left') }}</span>
                        <span class="ml-auto text-xs tabular-nums text-slate-500 dark:text-gray-400">{{ $g['lanes']['Done']->count() }}/{{ $g['all']->count() }} done · {{ $fmt($g['all']->sum('logged_hours')) }} / {{ $fmt(\App\Models\Sprint::hours($g['all'])) }}</span>
                    </div>
                    <div class="grid gap-px bg-slate-100 sm:grid-cols-3 dark:bg-gray-700">
                        @foreach ($g['lanes'] as $lane => $rows)
                            <div class="bg-white p-3 dark:bg-gray-800" data-test="lane">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">{{ $lane }} <span class="font-normal">({{ $rows->count() }})</span></p>
                                @forelse ($rows as $t)
                                    <a href="{{ route('tasks.show', $t) }}" wire:navigate class="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-slate-50 dark:hover:bg-gray-700" data-test="task-row">
                                        <x-ui.task-code :task="$t" />
                                        <span @class(['min-w-0 flex-1 truncate', 'text-slate-800 dark:text-gray-100' => $lane !== 'Done', 'text-slate-400 line-through' => $lane === 'Done'])>{{ $t->title }}</span>
                                        <span class="shrink-0 text-xs tabular-nums text-slate-500 dark:text-gray-400" title="Logged / estimate">{{ $fmt($t->logged_hours ?? 0) }} / {{ $fmt($t->hours()) }}</span>
                                    </a>
                                @empty
                                    <p class="px-1.5 text-xs text-slate-400">Nothing here.</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @empty
                <x-ui.empty-state title="Nothing assigned to you" message="No tasks in the active sprints are assigned to you. Switch to All tasks to see the rest." name="check" data-test="none-mine" />
            @endforelse
        </div>
    @endif
</div>
