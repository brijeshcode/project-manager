{{-- Budget per module. $modules: each with `tasks` = budgeted tasks (column loaded). Shows task amounts only, never hours or rates.
     Admin only: $billing (ProjectEconomics::invoicedTasks) and $paid (payments cover all invoiced); without them it is the client view. --}}
@php
    $billing ??= null;
    $admin = $billing !== null;
    $paid ??= false;
    $fmt = fn (int $cents) => \App\Support\Money::format($cents, $currency);
    $val = fn ($t) => $t->budget_amount === null ? 0 : \App\Support\Money::toCents((string) $t->budget_amount);
    $isDone = fn ($t) => $t->column?->stage === 'done';
    $all = $modules->flatMap->tasks;
    $doneCents = \App\Models\Sprint::value($all->filter($isDone));
    $totalCents = \App\Models\Sprint::value($all);
    $stageLabels = ['not_started' => 'Not started', 'active' => 'In progress', 'review' => 'In review', 'done' => 'Done'];
    $rows = $modules->map(fn ($m) => [$m, $m->status_override ?? \App\Models\Module::statusFromStages($m->tasks->map(fn ($t) => $t->column?->stage))])
        ->sortBy(fn ($r) => $r[1] === 'completed')->values();
@endphp
<div class="space-y-3" data-test="budget-breakdown">
    @unless ($admin)
        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <x-ui.card data-test="budget-completed"><div class="text-sm text-slate-500 dark:text-gray-400">Completed work</div><div class="text-2xl font-semibold">{{ $fmt($doneCents) }}</div></x-ui.card>
            <x-ui.card data-test="budget-pending"><div class="text-sm text-slate-500 dark:text-gray-400">Pending work</div><div class="text-2xl font-semibold">{{ $fmt($totalCents - $doneCents) }}</div></x-ui.card>
            <x-ui.card data-test="budget-total"><div class="text-sm text-slate-500 dark:text-gray-400">Total</div><div class="text-2xl font-semibold">{{ $fmt($totalCents) }}</div></x-ui.card>
        </div>
    @endunless

    @forelse($rows as $i => [$module, $status])
        @php
            $mb = $module->budgetAmount();
            $cap = $mb === null ? null : \App\Support\Money::toCents((string) $mb);
            $work = \App\Models\Sprint::value($module->tasks);
            $done = \App\Models\Sprint::value($module->tasks->filter($isDone));
            $tasks = $module->tasks->sortBy($isDone)->values();
            if ($admin) {
                $inv = $module->tasks->filter(fn ($t) => in_array($billing[$t->id] ?? null, ['sent', 'paid'], true))->sum($val);
                $paidAll = $paid || $module->tasks->filter(fn ($t) => ($billing[$t->id] ?? null) === 'paid')->sum($val) >= $work;
                $ready = $module->tasks->filter(fn ($t) => $isDone($t) && $val($t) > 0 && ! isset($billing[$t->id]))->sum($val);
                $drafted = $module->tasks->contains(fn ($t) => ($billing[$t->id] ?? null) === 'draft');
                [$bLabel, $bColor] = match (true) {
                    $work === 0 => [null, null],
                    $inv >= $work => $paidAll ? ['Paid', 'green'] : ['Invoiced', 'brand'],
                    $inv > 0 => ['Partly invoiced', 'amber'],
                    $drafted => ['Draft invoice', 'slate'],
                    default => ['Not invoiced', 'slate'],
                };
            }
        @endphp
        @if ($status === 'completed' && ($i === 0 || $rows[$i - 1][1] !== 'completed'))
            <h3 class="pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400" data-test="budget-completed-divider">Completed</h3>
        @endif
        <x-ui.card padding="p-0" data-test="budget-module" data-status="{{ $status }}" x-data="{ open: false }">
            <button type="button" @click="open = !open" :aria-expanded="open" class="w-full px-4 py-3 text-left sm:px-5" data-test="budget-module-toggle">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition" ::class="open && 'rotate-90'" />
                    <h3 class="min-w-0 flex-1 basis-32 truncate font-display text-sm font-semibold text-slate-900 dark:text-white">{{ $module->name }}</h3>
                    @if ($admin)<x-ui.badge data-test="budget-mode">{{ ucfirst($module->billingMode()) }}</x-ui.badge>@endif
                    <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$status]" data-test="budget-status">{{ \App\Models\Module::STATUS_LABELS[$status] }}</x-ui.badge>
                    @if ($admin && $bLabel)<x-ui.badge :color="$bColor" data-test="budget-billing" :title="$bLabel === 'Paid' ? 'Invoices marked paid, or payments cover everything invoiced on this project' : null">{{ $bLabel }}</x-ui.badge>@endif
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:flex sm:flex-wrap sm:gap-x-6" data-test="budget-module-totals">
                    <div class="flex gap-1.5"><dt class="text-slate-500 dark:text-gray-400">Budget</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $cap === null ? '—' : $fmt($cap) }}</dd></div>
                    <div class="flex gap-1.5"><dt class="text-slate-500 dark:text-gray-400">Tasks</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $fmt($work) }}</dd></div>
                    <div class="flex gap-1.5"><dt class="text-slate-500 dark:text-gray-400">Done</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $fmt($done) }}</dd></div>
                    @if ($admin)
                        <div class="flex gap-1.5"><dt class="text-slate-500 dark:text-gray-400">Invoiced</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $fmt($inv) }}</dd></div>
                        @if ($ready > 0)<div class="flex gap-1.5 text-amber-700 dark:text-amber-400" data-test="budget-ready"><dt>Ready to invoice</dt><dd class="font-semibold tabular-nums">{{ $fmt($ready) }}</dd></div>@endif
                        @if ($cap !== null && $work > $cap)<div class="flex gap-1.5 text-red-600 dark:text-red-400" data-test="budget-over"><dt>Over by</dt><dd class="font-semibold tabular-nums">{{ $fmt($work - $cap) }}</dd></div>@endif
                    @endif
                </dl>
                <x-ui.progress class="mt-2" :pct="$work > 0 ? $done / $work * 100 : ($status === 'completed' ? 100 : 0)" />
            </button>
            <div x-show="open" x-collapse style="display:none" class="border-t border-slate-100 dark:border-gray-700">
                @forelse($tasks as $task)
                    @php($stage = $task->column?->stage)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-slate-100 px-4 py-2 text-sm last:border-b-0 sm:px-5 dark:border-gray-700" data-test="budget-task">
                        <span class="min-w-0 flex-1 basis-48"><x-ui.task-code :task="$task" class="mr-1" />{{ $task->title }}</span>
                        @if ($stage)<x-ui.badge :color="\App\Support\Look::stageColor($stage)">{{ $stageLabels[$stage] ?? $stage }}</x-ui.badge>@endif
                        @if ($admin)
                            @php($b = $billing[$task->id] ?? null)
                            @if ($b === 'paid' || ($b === 'sent' && $paid))<x-ui.badge color="green" data-test="task-billing">Paid</x-ui.badge>
                            @elseif ($b === 'sent')<x-ui.badge color="brand" data-test="task-billing">Invoiced</x-ui.badge>
                            @elseif ($b === 'draft')<x-ui.badge data-test="task-billing">Draft invoice</x-ui.badge>
                            @elseif ($stage === 'done' && $val($task) > 0)<x-ui.badge color="amber" data-test="task-billing">Ready to invoice</x-ui.badge>
                            @endif
                        @endif
                        <span class="w-24 text-right font-medium tabular-nums">{{ $task->budget_amount === null ? '—' : $fmt($val($task)) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-3 text-sm text-slate-500 dark:text-gray-400">No priced tasks yet.</p>
                @endforelse
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No budgets yet" message="Module budgets will appear here." />
    @endforelse
</div>
