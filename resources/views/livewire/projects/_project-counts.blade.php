{{-- $countModules: modules; $countTasks: counted tasks (column loaded); $moduleDone: optional completed test (the portal judges by visible tasks only). --}}
@php
    $mDone = $countModules->filter($moduleDone ?? fn ($m) => $m->status() === 'completed')->count();
    $tDone = $countTasks->filter(fn ($t) => $t->column?->stage === 'done')->count();
@endphp
<div class="grid gap-3 sm:grid-cols-2" data-test="project-counts">
    @foreach([['Modules', $countModules->count(), $mDone, 'modules', 'layout-dashboard'], ['Tasks', $countTasks->count(), $tDone, 'tasks', 'list-checks']] as [$label, $total, $done, $key, $icon])
        <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-4 dark:border-gray-700 dark:bg-gray-800/40" data-test="counts-{{ $key }}">
            <div class="flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-gray-400"><x-icon :name="$icon" class="h-4 w-4" />{{ $label }}</div>
            <div class="mt-2 flex items-baseline gap-1.5">
                <span class="font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $done }}</span>
                <span class="text-sm text-slate-500 dark:text-gray-400">of <span data-test="count-total">{{ $total }}</span> completed</span>
            </div>
            <x-ui.progress class="mt-2" :pct="$total ? $done / $total * 100 : 0" :label="false" />
            <div class="mt-2 flex justify-between text-xs text-slate-500 dark:text-gray-400">
                <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ $done }} completed</span>
                <span class="font-medium text-slate-700 dark:text-gray-200">{{ $total - $done }} pending</span>
            </div>
        </div>
    @endforeach
</div>
