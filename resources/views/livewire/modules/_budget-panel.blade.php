{{-- Module budget panel (manage-money only): headline budget, usage bar, then Used / Remaining / Done value. $module, $project, $mb, $hourly. --}}
@php
    $s = $mb->summary();
    $localOf = fn (int $cents) => $mb->localCents($cents);
    $remaining = $s['remaining'];
    $over = $remaining < 0;
    $h = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $localCode = \App\Services\LocalCurrency::code();
    $foreign = strtoupper($project->currency) !== $localCode;
    $pct = $s['cap'] ? (int) min(100, round($s['used'] / $s['cap'] * 100)) : 0;
@endphp
<div class="mb-2 flex items-center gap-2">
    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-gray-700 dark:text-gray-300" data-test="bp-mode">{{ $hourly ? 'Hourly' : 'Fixed' }}</span>
</div>
@if ($s['cap'] === null)
    <p class="text-slate-400">Not set</p>
@else
    <div data-test="budget-panel">
        {{-- Headline: the budget itself, local equivalent beside it --}}
        <div data-test="bp-budget">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ \App\Support\Money::format($s['cap'], $project->currency) }}</span>
                @if ($foreign && ($l = $localOf($s['cap'])) !== null)
                    <span class="text-sm text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($l, $localCode) }}</span>
                @endif
            </div>
            @if ($foreign && $localOf($s['cap']) === null)
                <p class="text-xs text-slate-500">Local total unavailable, no exchange rate</p>
            @endif
            @if ($hourly)
                <p class="text-xs text-slate-500" data-test="bp-formula">{{ $h($module->budget_hours) }} h &times; {{ $h($module->hourly_rate) }} = {{ number_format($s['cap'] / 100, 2) }}</p>
            @endif
        </div>

        {{-- Usage bar --}}
        <div class="mt-3" title="{{ $pct }}% of the budget is used by task values">
            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-gray-700">
                <div @class(['h-full rounded-full', 'bg-blue-500' => ! $over, 'bg-rose-500' => $over]) style="width: {{ $over ? 100 : $pct }}%"></div>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">{{ $pct }}% used</p>
        </div>

        {{-- Rows: label left, amount right with the local equivalent small beside it --}}
        <dl class="mt-3 divide-y divide-slate-100 text-sm dark:divide-gray-700">
            @foreach ([
                ['Used', 'bp-used', $s['used']],
                ['Remaining', 'bp-remaining', $remaining],
                ['Done value', 'bp-done', $s['done']],
            ] as [$label, $test, $cents])
                <div class="flex items-baseline justify-between gap-3 py-2">
                    <dt class="shrink-0 text-slate-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd @class(['min-w-0 text-right font-medium', 'text-slate-900 dark:text-white' => ! ($test === 'bp-remaining' && $over), 'text-rose-600 dark:text-rose-400' => $test === 'bp-remaining' && $over]) data-test="{{ $test }}">
                        @if ($test === 'bp-remaining' && $over)
                            {{ $mb->format(-$remaining) }} over
                        @else
                            <span>{{ \App\Support\Money::format($cents, $project->currency) }}</span>
                            @if ($foreign && ($l = $localOf($cents)) !== null)
                                <span class="block text-xs font-normal text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($l, $localCode) }}</span>
                            @endif
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
    @if ($over)
        <p class="mt-2 text-xs font-medium text-rose-600 dark:text-rose-400" data-test="bp-over">{{ $mb->format(-$remaining) }} over: reduce billed hours or raise the module amount.</p>
    @endif
    @if ($s['unpriced'] > 0)
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400" data-test="bp-unpriced">{{ $s['unpriced'] }} {{ Str::plural('task', $s['unpriced']) }} unpriced</p>
    @endif
@endif
