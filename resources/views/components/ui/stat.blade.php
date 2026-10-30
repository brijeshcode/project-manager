@props(['label', 'value', 'hint' => null])

<div class="card p-5">
    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
    <p class="mt-2 font-display text-3xl font-bold tracking-tight text-slate-900 tabular-nums">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
</div>
