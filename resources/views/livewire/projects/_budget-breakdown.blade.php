{{-- Budget per module. $modules: each with `tasks` = budgeted tasks (column loaded). Shows task amounts only, never hours or rates. --}}
@php
    $fmt = fn (int $cents) => \App\Support\Money::format($cents, $currency);
    $all = $modules->flatMap->tasks;
    $doneCents = \App\Models\Sprint::value($all->filter(fn ($t) => $t->column?->stage === 'done'));
    $totalCents = \App\Models\Sprint::value($all);
@endphp
<div class="space-y-4" data-test="budget-breakdown">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.card data-test="budget-completed"><div class="text-sm text-slate-500 dark:text-gray-400">Completed work</div><div class="text-2xl font-semibold">{{ $fmt($doneCents) }}</div></x-ui.card>
        <x-ui.card data-test="budget-pending"><div class="text-sm text-slate-500 dark:text-gray-400">Pending work</div><div class="text-2xl font-semibold">{{ $fmt($totalCents - $doneCents) }}</div></x-ui.card>
        <x-ui.card data-test="budget-total"><div class="text-sm text-slate-500 dark:text-gray-400">Total</div><div class="text-2xl font-semibold">{{ $fmt($totalCents) }}</div></x-ui.card>
    </div>

    @forelse($modules as $module)
        @php($mb = $module->budgetAmount())
        <x-ui.card data-test="budget-module">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h3 class="font-display text-sm font-semibold text-slate-900">{{ $module->name }}</h3>
                <span class="text-sm text-slate-500 dark:text-gray-400">Budget <span class="font-semibold tabular-nums text-slate-900">{{ $mb === null ? '—' : $fmt(\App\Support\Money::toCents((string) $mb)) }}</span></span>
            </div>
            <table class="w-full text-sm">
                @foreach($module->tasks as $task)
                    <tr class="border-t border-slate-100">
                        <td class="py-2"><x-ui.task-code :task="$task" class="mr-1" />{{ $task->title }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $task->budget_amount === null ? '—' : $fmt(\App\Support\Money::toCents((string) $task->budget_amount)) }}</td>
                    </tr>
                @endforeach
                <tr class="border-t border-slate-200 font-semibold">
                    <td class="py-2">Tasks total</td>
                    <td class="py-2 text-right tabular-nums">{{ $fmt(\App\Models\Sprint::value($module->tasks)) }}</td>
                </tr>
            </table>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No budgets yet" message="Module budgets will appear here." />
    @endforelse
</div>
