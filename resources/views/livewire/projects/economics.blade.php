@php
    $e = $this->economics;
    $c = $e['currency'];
    $m = fn (?int $v) => $v === null ? '—' : \App\Support\Money::format($v, $c);
    $modules = $this->budgetModules;
    $billing = $this->invoicedTasks;
    $tasks = $modules->flatMap->tasks;
    $val = fn ($t) => $t->budget_amount === null ? 0 : \App\Support\Money::toCents((string) $t->budget_amount);
    $doneTasks = $tasks->filter(fn ($t) => $t->column?->stage === 'done');
    $work = $tasks->sum($val);
    $done = $doneTasks->sum($val);
    $budget = $modules->sum(fn ($mod) => $mod->budgetAmount() === null ? 0 : \App\Support\Money::toCents((string) $mod->budgetAmount()));
    $readyTasks = $doneTasks->filter(fn ($t) => $val($t) > 0 && ! isset($billing[$t->id]));
    $drafted = $tasks->filter(fn ($t) => ($billing[$t->id] ?? null) === 'draft')->sum($val);
    $outstanding = max(0, $e['invoiced_cents'] - $e['received_cents']);
    $paid = $e['invoiced_cents'] > 0 && $outstanding === 0;
    $hours = rtrim(rtrim(number_format($e['hours'], 2, '.', ''), '0'), '.');
    $label = 'text-xs font-medium text-slate-500 dark:text-gray-400';
    $value = 'mt-1 text-xl font-semibold tabular-nums text-slate-900 dark:text-white';
    $hint = 'mt-1 text-xs text-slate-500 dark:text-gray-400';
@endphp
<div>
    @unless ($embedded)
        <div class="mb-6">
            <a href="{{ route('projects.show', $this->project) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700" data-test="economics-back">&larr; {{ $this->project->name }}</a>
        </div>
        <h1 class="font-display text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Economics</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">{{ $this->project->name }} &middot; {{ $c }}</p>
    @endunless

    <div class="{{ $embedded ? '' : 'mt-6 ' }}mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6" data-test="economics-kpis">
        <x-ui.card padding="p-4" data-test="kpi-done">
            <div class="{{ $label }}">Work done</div>
            <div class="{{ $value }}">{{ $m($done) }}</div>
            <x-ui.progress class="mt-2" :pct="$work > 0 ? $done / $work * 100 : 0" />
            <div class="{{ $hint }}">of {{ $m($work) }} planned &middot; budget {{ $m($budget) }}</div>
        </x-ui.card>
        <x-ui.card padding="p-4" data-test="kpi-ready">
            <div class="{{ $label }}">Ready to invoice</div>
            <div class="{{ $value }} {{ $readyTasks->isNotEmpty() ? '!text-amber-600 dark:!text-amber-400' : '' }}">{{ $m($readyTasks->sum($val)) }}</div>
            <div class="{{ $hint }}">{{ $readyTasks->count() }} done {{ Str::plural('task', $readyTasks->count()) }} not on an invoice{{ $drafted ? ' · '.$m($drafted).' in drafts' : '' }}</div>
        </x-ui.card>
        <x-ui.card padding="p-4" data-test="kpi-invoiced">
            <div class="{{ $label }}">Invoiced</div>
            <x-ui.money-pair class="{{ $value }}" :cents="$e['invoiced_cents']" :currency="$c" :local="$e['invoiced_local']" :missing="$e['invoiced_missing_rate']" />
            <div class="{{ $hint }}">Sent invoices</div>
        </x-ui.card>
        <x-ui.card padding="p-4" data-test="kpi-received">
            <div class="{{ $label }}">Received</div>
            <x-ui.money-pair class="{{ $value }}" :cents="$e['received_cents']" :currency="$c" :local="$e['received_local']" :missing="$e['received_missing_rate']" />
            <div @class([$hint, '!text-red-600 dark:!text-red-400' => $outstanding > 0])>{{ $outstanding > 0 ? 'Outstanding '.$m($outstanding) : ($paid ? 'All invoices paid' : 'Nothing outstanding') }}</div>
        </x-ui.card>
        <x-ui.card padding="p-4" data-test="kpi-cost">
            <div class="{{ $label }}">Cost of logged hours</div>
            <div class="{{ $value }}">{{ $m($e['cost_cents']) }}</div>
            <div class="{{ $hint }}">Hours logged: {{ $hours }}</div>
        </x-ui.card>
        <x-ui.card padding="p-4" data-test="kpi-profit">
            <div class="{{ $label }}">Profit</div>
            <div class="{{ $value }} {{ $e['profit_cents'] < 0 ? '!text-red-600' : '!text-green-600' }}">{{ $m($e['profit_cents']) }}</div>
            <div class="{{ $hint }}">Received − cost &middot; {{ $m($e['hourly_received_cents']) }}/h earned</div>
        </x-ui.card>
    </div>

    @include('livewire.projects._budget-breakdown', ['modules' => $modules, 'currency' => $c, 'billing' => $billing, 'paid' => $paid])
</div>
