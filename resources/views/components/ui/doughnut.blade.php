{{-- Server-rendered SVG doughnut. counts: ['paid' => n, 'pending' => n, 'overdue' => n, 'void' => n] --}}
@props(['counts'])

@php
    // literal class names so Tailwind keeps them
    $segs = [
        'paid' => ['Paid', 'stroke-emerald-500', 'bg-emerald-500'],
        'pending' => ['Pending', 'stroke-amber-500', 'bg-amber-500'],
        'overdue' => ['Overdue', 'stroke-red-500', 'bg-red-500'],
        'void' => ['Void', 'stroke-gray-500', 'bg-gray-500'],
    ];
    $total = array_sum(array_map('intval', $counts));
    $acc = 0;
@endphp

<div {{ $attributes }}>
    <svg viewBox="0 0 120 120" class="mx-auto h-56 w-56" role="img" aria-label="Payment status">
        <circle cx="60" cy="60" r="42" fill="none" stroke-width="22" class="stroke-gray-100 dark:stroke-gray-700"/>
        @if ($total > 0)
            @foreach ($segs as $key => [$label, $stroke])
                @php $n = (int) ($counts[$key] ?? 0); @endphp
                @if ($n > 0)
                    @php $len = $n * 100 / $total; @endphp
                    <circle cx="60" cy="60" r="42" fill="none" stroke-width="22" pathLength="100" class="{{ $stroke }}"
                        stroke-dasharray="{{ round($len, 3) }} {{ round(100 - $len, 3) }}" stroke-dashoffset="{{ $acc > 0 ? round(-$acc, 3) : 0 }}" transform="rotate(-90 60 60)">
                        <title>{{ $label }}: {{ $n }} ({{ round($n * 100 / $total) }}%)</title>
                    </circle>
                    @php $acc += $len; @endphp
                @endif
            @endforeach
        @endif
    </svg>
    @if ($total === 0)
        <p class="mt-2 text-center text-sm text-gray-500 dark:text-gray-400">No invoices in this period</p>
    @endif
    <ul class="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
        @foreach ($segs as [$label, $stroke, $bg])
            <li class="flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-sm {{ $bg }}"></span>{{ $label }}</li>
        @endforeach
    </ul>
</div>
