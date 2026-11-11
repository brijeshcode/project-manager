<div>
    <div class="mb-6">
        <a href="{{ route('portal.dashboard') }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Your projects
        </a>
    </div>

    <x-ui.page-header :title="$project->name"
                      subtitle="Your tasks on this project. Request work, add comments, or approve completed items." />

    @php($currency = $project->currency)

    <div class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
        @foreach(\App\Livewire\Portal\ProjectView::TABS as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" data-test="portal-tab-{{ $key }}"
                    @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $tab === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'details')
        <x-ui.card class="space-y-4" data-test="portal-details">
            <div class="flex items-center justify-between gap-3">
                <x-ui.badge color="brand">{{ ucfirst($project->status) }}</x-ui.badge>
                @if($project->start_date)
                    <span class="text-xs text-slate-500">Started {{ $project->start_date->format('M j, Y') }}</span>
                @endif
            </div>
            @if($project->aim)
                <p class="text-sm font-medium text-slate-700">{{ $project->aim }}</p>
            @endif
            @if($project->description)
                <x-ui.markdown :text="$project->description" />
            @endif
            <x-ui.progress :pct="app(\App\Services\ProgressService::class)->forProject($project)" :label="false" />
        </x-ui.card>
        <div class="mt-4">@include('livewire.projects._project-counts')</div>
    @endif

    @if($tab === 'modules')
        @include('livewire.portal._request-forms')

        @include('livewire.projects._module-list', ['client' => true, 'canManage' => false, 'candidates' => null, 'budgetBlocked' => []])
    @endif


    @if($tab === 'sprints')
        <livewire:sprints.panel :project="$project" :key="'sprints-'.$project->id" />
    @endif

    @if($tab === 'board')
        <livewire:tasks.board :project="$project" :embedded="true" :key="'board-'.$project->id" />
    @endif

    @if($tab === 'budget')
        @include('livewire.projects._budget-breakdown', ['modules' => $budgetModules])
    @endif

    @if($tab === 'planned')
        @forelse($planned as $task)
            <x-ui.card class="mb-3 flex items-center justify-between gap-3 text-sm" data-test="portal-planned">
                <span><x-ui.task-code :task="$task" class="mr-1" />{{ $task->title }}</span>
                @if($task->deadline)<span class="text-xs text-slate-500">Due {{ $task->deadline->format('M j, Y') }}</span>@endif
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Nothing planned" message="Tasks not yet started will appear here." />
        @endforelse
    @endif

    @if($tab === 'resources')
        <livewire:resources.panel :project="$project" />
    @endif
</div>
