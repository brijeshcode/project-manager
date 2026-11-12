{{-- Started …, then Completed … (completed) or Last worked … (on hold / draft). $project, $lastWorked (date|null). --}}
<span class="inline-flex flex-wrap gap-x-2" data-test="project-dates">
    <span>{{ $project->start_date ? 'Started '.$project->start_date->format('M j, Y') : 'Not started' }}</span>
    @if ($project->status === 'completed' && $project->completed_at)
        <span class="font-medium text-emerald-600 dark:text-emerald-400" data-test="project-completed">Completed {{ $project->completed_at->format('M j, Y') }}</span>
    @elseif (in_array($project->status, ['on_hold', 'draft'], true))
        <span class="font-medium text-amber-600 dark:text-amber-400" data-test="project-last-worked">{{ $lastWorked ? 'Last worked '.$lastWorked->format('M j, Y') : 'No work yet' }}</span>
    @endif
</span>
