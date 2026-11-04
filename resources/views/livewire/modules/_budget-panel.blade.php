{{-- Module budget panel (manage-money only): Budget, Allocated, Remaining, Done value + the distribute helper. $module, $project, $mb, $hourly, $canManage, $distributing. --}}
@php
    $s = $mb->summary();
    $money = fn (int $cents) => \App\Support\Money::format($cents, $project->currency);
    $unitFmt = fn (?int $u) => $u === null ? '—' : $mb->format($u);
    $localOf = fn (int $cents) => $mb->localCents($cents);
    $remaining = $s['remaining'];
@endphp
@if ($s['cap'] === null)
    <p class="text-slate-400">Not set</p>
@else
    <dl class="space-y-2" data-test="budget-panel">
        <div>
            <dt class="text-xs text-slate-500">Budget</dt>
            <dd data-test="bp-budget">
                @if ($hourly)
                    {{ $unitFmt($s['cap']) }} <span class="text-xs text-slate-500">@ {{ $module->hourly_rate ?? 'no rate' }} = {{ $money((int) \App\Support\Money::toCents($module->budgetAmount() ?? '0')) }}</span>
                @else
                    <x-ui.money-pair :cents="$s['cap']" :currency="$project->currency" :local="$localOf($s['cap'])" :missing="$localOf($s['cap']) === null ? 1 : 0" />
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">Allocated <span class="text-slate-400">(counted tasks{{ $hourly ? ', hours' : '' }})</span></dt>
            <dd data-test="bp-allocated">
                @if ($hourly) {{ $unitFmt($s['allocated']) }}
                @else <x-ui.money-pair :cents="$s['allocated']" :currency="$project->currency" :local="$localOf($s['allocated'])" :missing="$localOf($s['allocated']) === null ? 1 : 0" />
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">Remaining</dt>
            <dd @class(['font-medium', 'text-rose-600 dark:text-rose-400' => $remaining < 0]) data-test="bp-remaining">
                @if ($remaining < 0)
                    {{ $unitFmt(-$remaining) }} over
                @elseif ($hourly) {{ $unitFmt($remaining) }}
                @else <x-ui.money-pair :cents="$remaining" :currency="$project->currency" :local="$localOf($remaining)" :missing="$localOf($remaining) === null ? 1 : 0" />
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">Done value</dt>
            <dd data-test="bp-done"><x-ui.money-pair :cents="$s['done']" :currency="$project->currency" :local="$localOf($s['done'])" :missing="$localOf($s['done']) === null ? 1 : 0" /></dd>
        </div>
    </dl>
    @if ($s['unpriced'] > 0)
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400" data-test="bp-unpriced">{{ $s['unpriced'] }} {{ Str::plural('task', $s['unpriced']) }} unpriced</p>
    @endif
    @if ($s['proposed'] > 0)
        <p class="mt-1 text-xs text-slate-500" data-test="bp-proposed">{{ $s['proposed'] }} proposed add-on{{ $s['proposed'] === 1 ? '' : 's' }} not counted yet</p>
    @endif
@endif

@if ($canManage && $s['cap'] !== null)
    @if ($hourly)
        <p class="mt-2 text-xs text-slate-500" data-test="distribute-na">Hourly client: task values are hours &times; the hourly rate, so there is nothing to distribute or rebalance.</p>
    @else
        @php $dp = $mb->distributePreview(); @endphp
        @if ($distributing)
            <div class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-gray-700" data-test="distribute-preview">
                @if (! $dp['ok'])
                    <p class="text-xs text-slate-500">{{ $dp['reason'] }}</p>
                @else
                    <p class="text-xs font-semibold text-slate-600">Share {{ $money($dp['remaining']) }} over unpriced tasks by hours</p>
                    <table class="mt-1 w-full text-xs">
                        <thead><tr class="text-left text-slate-500"><th class="py-0.5">Task</th><th class="text-right">Hours</th><th class="text-right">Amount</th></tr></thead>
                        @foreach ($dp['rows'] as $r)
                            <tr data-test="dp-row"><td class="truncate py-0.5">{{ $r['task']->code() }} {{ $r['task']->title }}</td><td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($r['hours'], 2), '0'), '.') ?: '0' }}</td><td class="text-right font-semibold tabular-nums">{{ $money($r['cents']) }}</td></tr>
                        @endforeach
                    </table>
                    <p class="mt-1 text-xs text-slate-500">Priced tasks are not touched. Nothing is saved until you confirm.</p>
                @endif
                <div class="mt-2 flex gap-2">
                    @if ($dp['ok'])<button type="button" wire:click="applyDistribute" class="btn btn-primary h-7 px-2.5 py-1 text-xs" data-test="apply-distribute">Apply</button>@endif
                    <button type="button" wire:click="$set('distributing', false)" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">{{ $dp['ok'] ? 'Cancel' : 'Close' }}</button>
                </div>
            </div>
        @else
            <button type="button" wire:click="openDistribute" class="btn btn-secondary mt-3 h-8 px-2.5 py-1 text-xs" @disabled(! $dp['ok']) title="{{ $dp['ok'] ? '' : $dp['reason'] }}" data-test="distribute">Distribute remaining budget by hours</button>
            @unless ($dp['ok'])<p class="mt-1 text-xs text-slate-500" data-test="distribute-why">{{ $dp['reason'] }}</p>@endunless
        @endif
    @endif
@endif
