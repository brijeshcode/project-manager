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
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card data-test="portal-details">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">About this project</h2>
                        <x-ui.badge color="brand">{{ ucfirst($project->status) }}</x-ui.badge>
                    </div>
                    @if($project->aim)
                        <div class="mb-4 flex gap-3 rounded-lg border-l-4 border-brand-500 bg-brand-50/60 px-4 py-3 dark:bg-brand-500/10">
                            <x-icon name="flag" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700 dark:text-brand-300">Aim</p>
                                <x-ui.markdown :text="$project->aim" class="text-slate-800 dark:text-gray-200" />
                            </div>
                        </div>
                    @endif
                    @if($project->description)
                        <x-ui.markdown :text="$project->description" class="max-w-none" />
                    @endif
                    @if(! $project->aim && ! $project->description)
                        <p class="text-sm text-slate-500 dark:text-gray-400">No description yet.</p>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Progress</h2>
                        @php($pct = app(\App\Services\ProgressService::class)->forClient($project)['pct'])
                        <x-ui.progress class="w-40" :pct="$pct" />
                    </div>
                    @include('livewire.projects._project-counts', ['moduleDone' => fn ($m) => $m->status_override === 'completed' || ($m->status_override === null && $m->tasks->isNotEmpty() && $m->tasks->every(fn ($t) => $t->column?->stage === 'done'))])
                </x-ui.card>
            </div>

            <div data-test="portal-timeline">@include('livewire.projects._timeline', ['client' => true])</div>
        </div>
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
            <x-ui.card class="mb-3 text-sm" data-test="portal-planned">
                <details open class="group">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5 font-medium text-slate-900 dark:text-white"><x-icon name="chevron-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" /><x-ui.task-code :task="$task" class="mr-1" />{{ $task->title }}</span>
                        @if($task->deadline)<span class="shrink-0 text-xs text-slate-500">Due {{ $task->deadline->format('M j, Y') }}</span>@endif
                    </summary>
                    <div class="mt-3 space-y-3 border-t border-slate-100 pt-3 dark:border-gray-700" data-test="portal-planned-details">
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-gray-400">
                            <span>Module: <span class="font-medium text-slate-700 dark:text-gray-200">{{ $modules->firstWhere('id', $task->module_id)?->name ?? '—' }}</span></span>
                            <span>Priority: <span class="font-medium text-slate-700 dark:text-gray-200">{{ \App\Support\Look::PRIORITIES[$task->priority][0] ?? '—' }}</span></span>
                            @if($task->budget_amount > 0)<span>Value: <span class="font-medium tabular-nums text-slate-700 dark:text-gray-200">{{ \App\Support\Money::format(\App\Support\Money::toCents((string) $task->budget_amount), $currency) }}</span></span>@endif
                        </div>
                        @if($task->description)
                            <x-ui.markdown :text="$task->description" class="max-w-none" />
                        @else
                            <p class="text-slate-500 dark:text-gray-400">No description yet.</p>
                        @endif
                    </div>
                </details>
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Nothing planned" message="Tasks not yet started will appear here." />
        @endforelse
    @endif

    @if($tab === 'resources')
        <livewire:resources.panel :project="$project" />
    @endif
</div>
