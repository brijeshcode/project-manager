{{-- $countModules: modules; $countTasks: counted tasks (column loaded). --}}
@php
    $mDone = $countModules->filter(fn ($m) => $m->status() === 'completed')->count();
    $tDone = $countTasks->filter(fn ($t) => $t->column?->stage === 'done')->count();
@endphp
<div class="grid gap-4 sm:grid-cols-2" data-test="project-counts">
    @foreach([['Modules', $countModules->count(), $mDone, 'modules'], ['Tasks', $countTasks->count(), $tDone, 'tasks']] as [$label, $total, $done, $key])
        <x-ui.card data-test="counts-{{ $key }}">
            <div class="text-sm text-slate-500 dark:text-gray-400">{{ $label }}</div>
            <div class="text-xl font-semibold">{{ $total }}</div>
            <div class="mt-1 text-xs text-slate-500 dark:text-gray-400">{{ $done }} completed &middot; {{ $total - $done }} pending</div>
        </x-ui.card>
    @endforeach
</div>
