@php
    $statuses = ['active' => 'Active', 'on_hold' => 'On hold', 'draft' => 'Draft', 'completed' => 'Completed'];
    $statusColor = ['active' => 'green', 'on_hold' => 'amber', 'draft' => 'slate', 'completed' => 'brand'];
    $progress = app(\App\Services\ProgressService::class);
    $allStats = $projects->mapWithKeys(fn ($p) => [$p->id => $progress->stats($p)]);
    $canMoney = auth()->user()->can('manage-money');
    $lastWorked = \App\Livewire\Projects\Index::lastWorked($projects->whereIn('status', ['on_hold', 'draft'])->pluck('id'));
@endphp
<div class="space-y-4" data-test="client-projects"
     x-data="{ view: (() => { try { return localStorage.getItem('clientProjectsView') || 'cards' } catch (e) { return 'cards' } })(),
               setView(v) { this.view = v; try { localStorage.setItem('clientProjectsView', v) } catch (e) {} } }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $projects->count() }} {{ Str::plural('project', $projects->count()) }} ·
            {{ $projects->whereIn('status', \App\Models\Project::CLIENT_STATUSES)->count() }} visible in the client portal
        </p>
        <div class="flex items-center gap-2">
            @include('livewire.projects._view-toggle', ['live' => false])
            <x-ui.filter-button for="client-projects" :active="($projectSearch !== '') + isset($statuses[$projectStatus])" />
            <a href="{{ route('projects.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary"><x-nav.icon name="plus" class="h-5 w-5" /> Add project</a>
        </div>
    </div>

    <x-ui.filter-panel for="client-projects" class="!mb-0">
        <div class="sm:w-80">
            <label for="projectSearch" class="sr-only">Search projects</label>
            <input wire:model="projectSearch" id="projectSearch" type="search" maxlength="100" placeholder="Search project…" class="input" />
        </div>
        <div class="sm:w-60">
            <label for="projectStatus" class="sr-only">Status</label>
            <select wire:model="projectStatus" id="projectStatus" class="select">
                @foreach (['' => 'All statuses'] + $statuses as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <x-slot:chips>
            @if ($projectSearch !== '')<x-ui.filter-chip :label="'Search: '.$projectSearch" wire:click="$set('projectSearch', '')" />@endif
            @isset($statuses[$projectStatus])<x-ui.filter-chip :label="'Status: '.$statuses[$projectStatus]" wire:click="$set('projectStatus', '')" />@endisset
        </x-slot:chips>
    </x-ui.filter-panel>

    @if ($projects->isEmpty() && ($projectSearch !== '' || $projectStatus !== ''))
        <x-ui.empty-state title="No matches" message="No projects match your filters." />
    @elseif ($projects->isEmpty())
        <x-ui.empty-state title="No projects yet" message="Projects for this client will appear here.">
            <x-slot name="action"><a href="{{ route('projects.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary">Add Project</a></x-slot>
        </x-ui.empty-state>
    @else
        <div x-show="view === 'list'" x-cloak>@include('livewire.projects._list', ['showClient' => false, 'clientTab' => true, 'allStats' => $allStats])</div>
        <div x-show="view === 'cards'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                @php
                    $s = $allStats[$project->id];
                    $pct = (int) round($s['pct']);
                    $inPortal = in_array($project->status, \App\Models\Project::CLIENT_STATUSES, true);
                    $left = $project->deadline && $project->status !== 'completed' ? (int) today()->diffInDays($project->deadline, false) : null;
                @endphp
                <div wire:key="project-{{ $project->id }}" class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800" data-test="client-project-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($project->key)<p class="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-gray-500">{{ $project->key }}</p>@endif
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="mt-0.5 block truncate font-display text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $project->name }}</a>
                        </div>
                        <x-ui.badge :color="$statusColor[$project->status] ?? 'slate'">{{ $statuses[$project->status] ?? ucfirst($project->status) }}</x-ui.badge>
                    </div>

                    <div class="mt-4">
                        <div class="mb-1.5 flex items-baseline justify-between">
                            <span class="text-xs font-medium text-slate-500 dark:text-gray-400">Progress</span>
                            <span class="font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $pct }}%</span>
                        </div>
                        <x-ui.progress :pct="$pct" :label="false" />
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                            <dt class="text-slate-500 dark:text-gray-400">Modules</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white" data-test="project-modules">{{ $s['modulesDone'] }} / {{ $s['modules'] }} done</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                            <dt class="text-slate-500 dark:text-gray-400">Tasks</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white" data-test="project-tasks">{{ $s['tasksDone'] }} / {{ $s['tasks'] }} done</dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                            <dt class="text-slate-500 dark:text-gray-400">Deadline</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">
                                @if ($project->deadline)
                                    {{ $project->deadline->format('M j, Y') }}
                                    @if ($left !== null)<span @class(['block font-medium', 'text-rose-600 dark:text-rose-400' => $left < 0, 'text-slate-500 dark:text-gray-400' => $left >= 0])>{{ $left >= 0 ? $left.' '.Str::plural('day', $left).' left' : abs($left).' '.Str::plural('day', abs($left)).' overdue' }}</span>@endif
                                @else
                                    <span class="font-normal text-slate-400 dark:text-gray-500">No deadline</span>
                                @endif
                            </dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-gray-700/40">
                            <dt class="text-slate-500 dark:text-gray-400">Budget</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white">
                                @if ($canMoney && (float) $project->final_budget > 0)
                                    {{ \App\Support\Money::format(\App\Support\Money::toCents($project->final_budget), $project->currency) }}
                                @else
                                    <span class="font-normal text-slate-400 dark:text-gray-500">—</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">@include('livewire.projects._dates', ['lastWorked' => $lastWorked[$project->id] ?? null])</p>

                    <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-gray-700">
                        <span @class(['inline-flex items-center gap-1 text-xs', 'text-emerald-600 dark:text-emerald-400' => $inPortal, 'text-slate-400 dark:text-gray-500' => ! $inPortal]) title="{{ $inPortal ? 'The client sees this project in their portal' : 'Draft and on-hold projects are hidden from the client portal' }}" data-test="portal-visibility">
                            <x-icon :name="$inPortal ? 'eye' : 'eye-off'" class="h-3.5 w-3.5" />{{ $inPortal ? 'In client portal' : 'Hidden from portal' }}
                        </span>
                        <div class="flex items-center">
                            <a href="{{ route('projects.board', $project) }}" wire:navigate class="btn btn-ghost px-2" title="Board" aria-label="Board for {{ $project->name }}"><x-icon name="columns-3" class="h-4 w-4" /></a>
                            <a href="{{ route('projects.edit', $project) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $project->name }}"><x-icon name="pencil" class="h-4 w-4" /></a>
                            <button type="button" x-on:click="ask = @js(['method' => 'deleteProject', 'id' => $project->id, 'title' => 'Delete project', 'text' => 'Delete "'.$project->name.'" with all its modules, tasks and sprints? This cannot be undone. Projects with payments or invoiced work cannot be deleted.'])" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $project->name }}" data-test="deleteProject-{{ $project->id }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
