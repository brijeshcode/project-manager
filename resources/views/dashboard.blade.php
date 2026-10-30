<x-app-layout>
    @php
        $user = auth()->user();
        $isAdmin = $user->hasRole('super_admin');
        $first = explode(' ', trim($user->name))[0] ?? $user->name;
    @endphp

    <x-ui.page-header
        title="{{ 'Welcome back, ' . $first }}"
        subtitle="Here's where things stand across your work." />

    @if ($isAdmin)
        @php
            $projectCount = \App\Models\Project::count();
            $clientCount  = \App\Models\Client::count();
            $openTasks    = \App\Models\Task::where('board_column', '!=', 'Done')->count();
            $hoursWeek    = (float) \App\Models\TimeEntry::whereBetween('worked_on', [now()->startOfWeek(), now()->endOfWeek()])->sum('hours');
            $recent       = \App\Models\Project::with('client')->latest()->take(5)->get();
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Projects" :value="$projectCount" hint="across all clients" />
            <x-ui.stat label="Clients" :value="$clientCount" />
            <x-ui.stat label="Open tasks" :value="$openTasks" hint="not yet done" />
            <x-ui.stat label="Hours this week" :value="number_format($hoursWeek, 1)" />
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-display text-lg font-semibold text-slate-900">Recent projects</h2>
                    <a href="{{ route('projects.index') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:text-brand-700">View all</a>
                </div>
                @if ($recent->isEmpty())
                    <x-ui.empty-state title="No projects yet" message="Create your first client, then spin up a project for them.">
                        <x-slot name="action"><a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">Add a client</a></x-slot>
                    </x-ui.empty-state>
                @else
                    <div class="card divide-y divide-slate-100 p-0">
                        @foreach ($recent as $project)
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ $project->name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ $project->client->name }}</p>
                                </div>
                                <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Quick actions</h2>
                <div class="space-y-2">
                    <a href="{{ route('projects.create') }}" wire:navigate class="card flex items-center gap-3 p-4 transition hover:shadow-card-hover">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-lg font-bold text-brand-600">+</span>
                        <span class="text-sm font-semibold text-slate-800">New project</span>
                    </a>
                    <a href="{{ route('clients.create') }}" wire:navigate class="card flex items-center gap-3 p-4 transition hover:shadow-card-hover">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-lg font-bold text-brand-600">+</span>
                        <span class="text-sm font-semibold text-slate-800">New client</span>
                    </a>
                </div>
            </div>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <a href="{{ route('projects.index') }}" wire:navigate class="card flex items-center justify-between p-6 transition hover:shadow-card-hover">
                <div>
                    <p class="font-display text-base font-semibold text-slate-900">Your projects</p>
                    <p class="mt-1 text-sm text-slate-500">Jump into the work assigned to you.</p>
                </div>
                <span class="text-lg text-brand-600">&rarr;</span>
            </a>
        </div>
    @endif
</x-app-layout>
