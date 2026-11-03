<x-app-layout>
    @if (auth()->user()->can('manage-money'))
        <livewire:dashboard />
    @else
        @php
            $user = auth()->user();
            $first = explode(' ', trim($user->name))[0] ?? $user->name;
            $projects = \App\Models\Project::with('client')->orderBy('name')
                ->whereHas('members', fn ($q) => $q->where('users.id', $user->id)->where('project_members.active', true))
                ->get();
            $myOpenTasks = \App\Models\Task::whereDoesntHave('column', fn ($q) => $q->where('stage', 'done'))
                ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))->count();
        @endphp
        <x-ui.page-header title="{{ 'Welcome back, ' . $first }}" subtitle="Here's where things stand across your work." />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.stat label="Your projects" icon="folder" color="blue" :value="$projects->count()" />
            <x-ui.stat label="Open tasks assigned to you" icon="check" color="purple" :value="$myOpenTasks" />
        </div>

        <div class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Your projects</h2>
            @if ($projects->isEmpty())
                <x-ui.empty-state title="No projects yet" message="Projects you're added to will show up here." />
            @else
                <div class="card divide-y divide-gray-100 p-0 dark:divide-gray-700">
                    @foreach ($projects as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate class="link flex items-center justify-between gap-4 px-5 py-4 text-sm font-semibold">
                            <span class="truncate">{{ $project->name }}</span>
                            <span class="truncate text-xs font-normal text-gray-500 dark:text-gray-400">{{ $project->client->name }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</x-app-layout>
