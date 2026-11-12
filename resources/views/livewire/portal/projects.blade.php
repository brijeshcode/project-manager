<div>
    <x-ui.page-header title="Your projects" subtitle="Progress on the work we're doing for you.">
        <x-slot:actions>@include('livewire.projects._view-toggle', ['live' => true])</x-slot:actions>
    </x-ui.page-header>

    @if ($projects->isEmpty())
        <x-ui.empty-state title="Nothing here yet" message="Your projects will appear here as soon as they're set up." />
    @elseif ($view === 'list')
        <div class="card overflow-x-auto" data-test="portal-projects-list">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th class="table-th">Project</th><th class="table-th">Status</th><th class="table-th">Completion</th>
                    <th class="table-th">Modules</th><th class="table-th">Tasks</th><th class="table-th">Deadline</th><th class="table-th text-right">Amount</th>
                </tr></thead>
                <tbody>
                @foreach ($projects as $project)
                    @php $s = $stats[$project->id]; @endphp
                    <tr wire:key="ppl-{{ $project->id }}" class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td font-medium"><a href="{{ route('portal.project', $project) }}" wire:navigate class="link">{{ $project->name }}</a></td>
                        <td class="table-td"><x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge></td>
                        <td class="table-td"><div class="flex items-center gap-2"><x-ui.progress class="w-20" :pct="$s['pct']" :label="false" /><span class="tabular-nums">{{ round($s['pct']) }}%</span></div></td>
                        <td class="table-td whitespace-nowrap tabular-nums">{{ $s['modulesDone'] }} / {{ $s['modules'] }}</td>
                        <td class="table-td whitespace-nowrap tabular-nums">{{ $s['tasksDone'] }} / {{ $s['tasks'] }}</td>
                        <td class="table-td whitespace-nowrap">{{ $project->deadline?->format('M j, Y') ?? '—' }}</td>
                        <td class="table-td whitespace-nowrap text-right tabular-nums">{{ (float) $project->final_budget > 0 ? \App\Support\Money::format(\App\Support\Money::toCents((string) $project->final_budget), $project->currency) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                @php $s = $stats[$project->id]; @endphp
                <a href="{{ route('portal.project', $project) }}" wire:navigate wire:key="pp-{{ $project->id }}"
                   class="card group flex flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-card-hover" data-test="portal-project-card">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="truncate text-base font-semibold text-gray-900 group-hover:text-blue-600 dark:text-white">{{ $project->name }}</h3>
                        <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
                    </div>

                    <div class="mt-4">
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-gray-500 dark:text-gray-400">Completion</span>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ round($s['pct']) }}%</span>
                        </div>
                        <x-ui.progress :pct="$s['pct']" :label="false" />
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Modules</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-white" data-test="pp-modules">{{ $s['modulesDone'] }} / {{ $s['modules'] }} <span class="font-normal text-gray-500 dark:text-gray-400">done</span></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Tasks</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-white" data-test="pp-tasks">{{ $s['tasksDone'] }} / {{ $s['tasks'] }} <span class="font-normal text-gray-500 dark:text-gray-400">done</span></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Deadline</dt>
                            <dd class="mt-0.5 font-semibold text-gray-900 dark:text-white">{{ $project->deadline?->format('M j, Y') ?? 'No deadline' }}</dd>
                        </div>
                        @if ((float) $project->final_budget > 0)
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Amount</dt>
                                <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-white" data-test="pp-amount">{{ \App\Support\Money::format(\App\Support\Money::toCents((string) $project->final_budget), $project->currency) }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if (($expected[$project->id] ?? 0) > 0)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Expected: <span class="font-semibold tabular-nums">{{ \App\Support\Money::format($expected[$project->id], $currency) }}</span> <span>(forecast, not yet invoiced)</span></p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
