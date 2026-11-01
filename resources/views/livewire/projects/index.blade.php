<div>
    <x-ui.page-header title="Projects" subtitle="Every project across your clients, with live progress.">
        @unless (auth()->user()->hasRole('client'))
            <x-slot name="actions">
                <a href="{{ route('projects.create') }}" wire:navigate class="btn btn-primary">
                    <span class="text-base leading-none">+</span> New project
                </a>
            </x-slot>
        @endunless
    </x-ui.page-header>

    <x-ui.filter-card>
        <div class="sm:col-span-2">
            <label for="search" class="sr-only">Search projects</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by project or client..." class="input" />
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select wire:model.live="status" id="status" class="select">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="on_hold">On hold</option>
                <option value="completed">Completed</option>
            </select>
        </div>
    </x-ui.filter-card>

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
                    $checklist = app(\App\Services\ProgressService::class)->forProject($project);
                    $timeUsed  = $project->timeBar() * 100;
                @endphp
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}"
                   class="card group p-5 transition hover:-translate-y-0.5 hover:shadow-card-hover">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate font-display text-base font-semibold text-slate-900 group-hover:text-brand-700">{{ $project->name }}</h3>
                            <p class="mt-0.5 truncate text-sm text-slate-500">{{ $project->client->name }}</p>
                        </div>
                        <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst(str_replace("_", " ", $project->status)) }}</x-ui.badge>
                    </div>
                    <div class="mt-5">
                        <x-ui.health-meter :checklist="$checklist" :time="$timeUsed" compact />
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-6">{{ $projects->links() }}</div>
    @endif
</div>
