<div>
    <x-ui.page-header title="Your projects" subtitle="Progress on the work we're doing for you." />

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <x-ui.stat label="Account balance" icon="currency" color="blue"
            :value="$balance < 0 ? 'You owe '.\App\Support\Money::format(-$balance, $currency) : ($balance > 0 ? 'Credit '.\App\Support\Money::format($balance, $currency) : 'All settled')" />
        <x-ui.stat label="Projects" icon="folder" color="purple" :value="$projects->count()" />
    </div>

    @if ($projects->isEmpty())
        <x-ui.empty-state title="Nothing here yet" message="Your projects will appear here as soon as they're set up." />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                @php $checklist = app(\App\Services\ProgressService::class)->forProject($project); @endphp
                <a href="{{ route('portal.project', $project) }}"
                   class="card group p-5 transition hover:-translate-y-0.5 hover:shadow-card-hover">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="truncate text-base font-semibold text-gray-900 group-hover:text-blue-600 dark:text-white">{{ $project->name }}</h3>
                        <x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst($project->status) }}</x-ui.badge>
                    </div>
                    <div class="mt-5">
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-gray-500 dark:text-gray-400">Progress</span>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ round($checklist) }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ round($checklist) }}%"></div>
                        </div>
                    </div>
                    @if (($expected[$project->id] ?? 0) > 0)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Expected: <span class="font-semibold tabular-nums">{{ \App\Support\Money::format($expected[$project->id], $currency) }}</span> <span>(forecast, not yet invoiced)</span></p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
