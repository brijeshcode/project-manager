{{-- Server-rendered SVG bar chart (no JS library). points: [['label' => 'Oct 5', 'cents' => 1500], ...] --}}
@props(['points', 'currency'])

@php
    $w = 600; $h = 160; $padL = 4; $padB = 22; $padT = 8;
    $peak = (int) collect($points)->max('cents');
    $max = max(1, $peak);
    $n = max(1, count($points));
    $slot = ($w - $padL) / $n;
    $bar = max(2, min(36, $slot * 0.7));
    $plotH = $h - $padB - $padT;
    $step = max(1, (int) ceil($n / 6));
@endphp

<div {{ $attributes }}>
    <div class="mb-1 flex items-baseline justify-between text-xs text-gray-500 dark:text-gray-400">
        <span class="font-medium">{{ $currency }}</span>
        <span>Peak {{ \App\Support\Money::format($peak, $currency) }}</span>
    </div>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-40 w-full" role="img" aria-label="Revenue in {{ $currency }}">
        <line x1="0" y1="{{ $h - $padB }}" x2="{{ $w }}" y2="{{ $h - $padB }}" class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1"/>
        <line x1="0" y1="{{ $padT }}" x2="{{ $w }}" y2="{{ $padT }}" class="stroke-gray-100 dark:stroke-gray-800" stroke-width="1" stroke-dasharray="3 3"/>
        @foreach ($points as $i => $p)
            @php
                $bh = $p['cents'] > 0 ? max(2, ($p['cents'] / $max) * $plotH) : 0;
                $x = $padL + $i * $slot + ($slot - $bar) / 2;
            @endphp
            <g>
                <title>{{ $p['label'] }}: {{ \App\Support\Money::format($p['cents'], $currency) }}</title>
                @if ($bh > 0)
                    <rect x="{{ round($x, 2) }}" y="{{ round($h - $padB - $bh, 2) }}" width="{{ round($bar, 2) }}" height="{{ round($bh, 2) }}" rx="2" class="fill-blue-500 hover:fill-blue-600 dark:fill-blue-400"/>
                @endif
                {{-- invisible full-height hit area so the tooltip works on empty days too --}}
                <rect x="{{ round($padL + $i * $slot, 2) }}" y="{{ $padT }}" width="{{ round($slot, 2) }}" height="{{ $plotH }}" fill="transparent"/>
                @if ($i % $step === 0)
                    <text x="{{ round($x + $bar / 2, 2) }}" y="{{ $h - 6 }}" text-anchor="middle" class="fill-gray-500 dark:fill-gray-400" font-size="10">{{ $p['label'] }}</text>
                @endif
            </g>
        @endforeach
    </svg>
</div>
