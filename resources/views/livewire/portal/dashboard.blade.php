<div>
    <x-ui.page-header title="Your projects" subtitle="Progress on the work we're doing for you." />

    @if ($projects->isEmpty())
        <x-ui.empty-state title="Nothing here yet" message="Your projects will appear here as soon as they're set up." />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                @php $checklist = app(\App\Services\ProgressService::class)->forProject($project); @endphp
                <a href="{{ route('portal.project', $project) }}"
                   class="card group p-5 transition hover:-translate-y-0.5 hover:shadow-card-hover">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="truncate font-display text-base font-semibold text-slate-900 group-hover:text-brand-700">{{ $project->name }}</h3>
                        <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
                    </div>
                    <div class="mt-5">
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-slate-500">Progress</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ round($checklist) }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ round($checklist) }}%"></div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
