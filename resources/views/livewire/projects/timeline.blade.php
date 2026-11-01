<div class="space-y-4">
    <div>
        <div class="mb-1 flex items-center justify-between text-sm">
            <span class="font-medium text-slate-700">Checklist progress</span>
            <span class="font-semibold tabular-nums text-slate-900">{{ number_format($checklistProgress, 1) }}%</span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ min($checklistProgress, 100) }}%"></div>
        </div>
    </div>

    <div>
        @php $pct = $estimated > 0 ? min(($logged / $estimated) * 100, 100) : 0; @endphp
        <div class="mb-1 flex items-center justify-between text-sm">
            <span class="font-medium text-slate-700">Time logged</span>
            <span class="font-semibold tabular-nums text-slate-900">{{ number_format($logged, 1) }}h / {{ number_format($estimated, 1) }}h</span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full transition-all {{ $pct >= 100 ? 'bg-rose-500' : ($pct >= 75 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                 style="width: {{ $pct }}%"></div>
        </div>
    </div>
</div>
