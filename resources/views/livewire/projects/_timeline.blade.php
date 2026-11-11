{{-- Project timeline: days used / left, key dates. $client hides internal planning (buffer). --}}
@php
    $client ??= false;
    $dStatus = $project->deadlineStatus();
    $left = $project->deadline ? (int) today()->diffInDays($project->deadline, false) : null;
    $estimate = $project->start_date ? $project->estimatedCompletion() : null;
@endphp
<x-ui.card>
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Timeline</h2>
        @if ($dStatus) <x-ui.badge :color="$dStatus[1]">{{ $dStatus[0] }}</x-ui.badge> @endif
    </div>
    @if ($project->deadline)
        <div class="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-center dark:bg-gray-800/40">
            @if ($project->status === 'completed')
                <p class="text-xs text-slate-500 dark:text-gray-400">Completed</p>
                <p class="font-display text-2xl font-bold text-slate-900 dark:text-white">{{ $project->completed_at?->format('M j, Y') ?? '—' }}</p>
            @else
                @php $used = $project->start_date ? max(0, (int) $project->start_date->diffInDays(today(), false)) : null; @endphp
                <div @class(['grid gap-2', 'grid-cols-2 divide-x divide-slate-200 dark:divide-gray-700' => $used !== null])>
                    @if ($used !== null)
                        <div data-test="days-used">
                            <p class="font-display text-3xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $used }}</p>
                            <p class="text-xs text-slate-500 dark:text-gray-400">{{ Str::plural('day', $used) }} used</p>
                        </div>
                    @endif
                    <div data-test="days-left">
                        <p @class(['font-display text-3xl font-bold tabular-nums', 'text-rose-600' => $left < 0, 'text-slate-900 dark:text-white' => $left >= 0])>{{ abs($left) }}</p>
                        <p class="text-xs text-slate-500 dark:text-gray-400">{{ $left >= 0 ? Str::plural('day', $left).' left' : Str::plural('day', abs($left)).' overdue' }}</p>
                    </div>
                </div>
                @if ($used !== null && $used + max(0, $left) > 0)
                    <x-ui.progress class="mt-3" :pct="min(100, $used / ($used + max(0, $left)) * 100)" :label="false" />
                @endif
            @endif
        </div>
    @endif
    <dl class="space-y-2.5 text-sm">
        @foreach ([['Start date', $project->start_date?->format('M j, Y') ?? '—'], ['Deadline', $project->deadline?->format('M j, Y') ?? 'Not set'], ['Projected deadline', $estimate?->format('M j, Y') ?? '—']] + ($client ? [] : [3 => ['Buffer', rtrim(rtrim(number_format((float) $project->buffer_percent, 2), '0'), '.').'%']]) as [$k, $v])
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-gray-400">{{ $k }}</dt>
                <dd class="font-medium text-slate-900 dark:text-white">{{ $v }}</dd>
            </div>
        @endforeach
    </dl>
</x-ui.card>
