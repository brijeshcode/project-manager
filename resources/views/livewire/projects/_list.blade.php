{{-- Compact project table. $projects, $lastWorked (id => date), $showClient, $clientTab (Edit/Board/Delete actions with the confirm modal). --}}
@php
    $statuses = ['active' => 'Active', 'on_hold' => 'On hold', 'draft' => 'Draft', 'completed' => 'Completed'];
    $statusColor = ['active' => 'green', 'on_hold' => 'amber', 'draft' => 'slate', 'completed' => 'brand'];
    $progress = app(\App\Services\ProgressService::class);
    $canMoney = auth()->user()->can('manage-money');
@endphp
<div class="card overflow-x-auto" data-test="project-list">
    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
        <thead>
            <tr>
                <th class="table-th">Project</th>
                @if ($showClient)<th class="table-th">Client</th>@endif
                <th class="table-th">Status</th><th class="table-th">Progress</th><th class="table-th">Modules</th><th class="table-th">Tasks</th>
                <th class="table-th">Deadline</th>
                @if ($canMoney)<th class="table-th text-right">Budget</th>@endif
                <th class="table-th">Dates</th>
                @if ($clientTab)<th class="table-th"></th>@endif
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
            @foreach ($projects as $project)
                @php $s = isset($allStats) ? $allStats[$project->id] : $progress->stats($project); $pct = (int) round($s['pct']); @endphp
                <tr wire:key="project-row-{{ $project->id }}" data-test="project-row">
                    <td class="table-td"><a href="{{ route('projects.show', $project) }}" wire:navigate class="link font-medium">{{ $project->name }}</a></td>
                    @if ($showClient)<td class="table-td">{{ $project->client->name }}</td>@endif
                    <td class="table-td"><x-ui.badge :color="$statusColor[$project->status] ?? 'slate'">{{ $statuses[$project->status] ?? ucfirst($project->status) }}</x-ui.badge></td>
                    <td class="table-td">
                        <div class="flex items-center gap-2"><x-ui.progress class="w-20" :pct="$pct" :label="false" /><span class="tabular-nums">{{ $pct }}%</span></div>
                    </td>
                    <td class="table-td whitespace-nowrap tabular-nums">{{ $s['modulesDone'] }} / {{ $s['modules'] }}</td>
                    <td class="table-td whitespace-nowrap tabular-nums">{{ $s['tasksDone'] }} / {{ $s['tasks'] }}</td>
                    <td class="table-td whitespace-nowrap">{{ $project->deadline?->format('M j, Y') ?? '—' }}</td>
                    @if ($canMoney)
                        <td class="table-td whitespace-nowrap text-right tabular-nums">{{ (float) $project->final_budget > 0 ? \App\Support\Money::format(\App\Support\Money::toCents($project->final_budget), $project->currency) : '—' }}</td>
                    @endif
                    <td class="table-td text-xs text-slate-500 dark:text-gray-400">@include('livewire.projects._dates', ['lastWorked' => $lastWorked[$project->id] ?? null])</td>
                    @if ($clientTab)
                        <td class="table-td whitespace-nowrap text-right">
                            <a href="{{ route('projects.board', $project) }}" wire:navigate class="btn btn-ghost px-2" title="Board" aria-label="Board for {{ $project->name }}"><x-icon name="columns-3" class="h-4 w-4" /></a>
                            <a href="{{ route('projects.edit', $project) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $project->name }}"><x-icon name="pencil" class="h-4 w-4" /></a>
                            <button type="button" x-on:click="ask = @js(['method' => 'deleteProject', 'id' => $project->id, 'title' => 'Delete project', 'text' => 'Delete "'.$project->name.'" with all its modules, tasks and sprints? This cannot be undone. Projects with payments or invoiced work cannot be deleted.'])" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $project->name }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
