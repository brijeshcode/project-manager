{{-- Server-rendered SVG line chart (no JS library). points: [['label' => 'Oct 5', 'cents' => 1500], ...] --}}
@props(['points', 'currency'])

@php
    $w = 600; $h = 256; $padL = 56; $padR = 14; $padT = 26; $padB = 60;
    $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB; $baseY = $padT + $plotH;
    $n = count($points);
    $peak = (int) collect($points)->max('cents');

    // nice scale in integer minor units: step is 1/2/5 x 10^n, ~5 intervals, starting at zero
    if ($peak <= 0) {
        $step = 10; $top = 100; // 0.00 - 1.00 by 0.10, like an empty Chart.js axis
    } else {
        $raw = max(1, intdiv($peak + 4, 5)); $mag = 1;
        while ($mag * 10 <= $raw) { $mag *= 10; }
        $step = $mag;
        foreach ([1, 2, 5, 10] as $m) { if ($mag * $m >= $raw) { $step = $mag * $m; break; } }
        $top = intdiv($peak + $step - 1, $step) * $step;
    }
    $ticks = range(0, $top, $step);

    $xs = []; $ys = [];
    foreach ($points as $i => $p) {
        $xs[$i] = $n > 1 ? $padL + $plotW * $i / ($n - 1) : $padL + $plotW / 2;
        $ys[$i] = $baseY - $plotH * $p['cents'] / $top;
    }

    // smooth path through the points: Chart.js-style bezier, tension 0.4, control y clamped to the plot
    $clamp = fn ($y) => max($padT, min($baseY, $y));
    $d = '';
    foreach ($xs as $i => $x) {
        if ($i === 0) { $d = 'M'.round($x, 2).' '.round($ys[0], 2); continue; }
        $cp = function ($k, $dir) use ($xs, $ys, $n, $clamp) {
            // control point of point $k towards neighbour $dir (-1 previous, +1 next)
            $a = $xs[max(0, $k - 1)]; $b = $ys[max(0, $k - 1)]; $c = $xs[min($n - 1, $k + 1)]; $e = $ys[min($n - 1, $k + 1)];
            $d01 = hypot($xs[$k] - $a, $ys[$k] - $b); $d12 = hypot($c - $xs[$k], $e - $ys[$k]);
            $s = $d01 + $d12 > 0 ? 0.4 * ($dir < 0 ? $d01 : $d12) / ($d01 + $d12) : 0;
            return [$xs[$k] + $dir * $s * ($c - $a), $clamp($ys[$k] + $dir * $s * ($e - $b))];
        };
        [$c1x, $c1y] = $cp($i - 1, 1); [$c2x, $c2y] = $cp($i, -1);
        $d .= ' C'.round($c1x, 2).' '.round($c1y, 2).' '.round($c2x, 2).' '.round($c2y, 2).' '.round($x, 2).' '.round($ys[$i], 2);
    }
    // value labels above the dots: compact text from integer minor units (display rounding only)
    $compact = function (int $cents) {
        $u = intdiv($cents + 50, 100);
        if ($u < 10000) { return number_format($u); }
        $t = intdiv($u + 50, 100); // tenths of K
        if ($t < 10000) { return intdiv($t, 10).($t % 10 ? '.'.($t % 10) : '').'K'; }
        $t = intdiv($u + 50000, 100000); // tenths of M

        return number_format(intdiv($t, 10)).($t % 10 ? '.'.($t % 10) : '').'M';
    };
    $order = collect($points)->map(fn ($p, $i) => [$p['cents'], $i])->filter(fn ($r) => $r[0] > 0)
        ->sort(fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]])->pluck(1);
    $labels = []; $spans = [];
    foreach ($order as $i) {
        $txt = $compact($points[$i]['cents']); $lw = 6 * strlen($txt);
        $x = $xs[$i]; $anchor = 'middle'; $l = $x - $lw / 2; $r = $x + $lw / 2;
        if ($l < 2) { $anchor = 'start'; $x = 2; $l = 2; $r = 2 + $lw; }
        elseif ($r > $w - 2) { $anchor = 'end'; $x = $w - 2; $r = $x; $l = $x - $lw; }
        foreach ($spans as [$a, $b]) { if ($l < $b + 4 && $r > $a - 4) { continue 2; } }
        $spans[] = [$l, $r];
        $labels[$i] = [$txt, $x, max(11, $ys[$i] - 9), $anchor];
    }
    $every = max(1, (int) ceil($n / 12));
@endphp

<div {{ $attributes }}>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-64 w-full" role="img" aria-label="Revenue in {{ $currency }}">
        @foreach ($ticks as $t)
            @php $y = round($baseY - $plotH * $t / $top, 2); @endphp
            <line x1="{{ $padL }}" y1="{{ $y }}" x2="{{ $w - $padR }}" y2="{{ $y }}" class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" data-grid="h"/>
            <text x="{{ $padL - 8 }}" y="{{ $y + 3.5 }}" text-anchor="end" class="fill-gray-500 dark:fill-gray-400" font-size="11">{{ number_format($t / 100, 2) }}</text>
        @endforeach
        @foreach ($points as $i => $p)
            <line x1="{{ round($xs[$i], 2) }}" y1="{{ $padT }}" x2="{{ round($xs[$i], 2) }}" y2="{{ $baseY }}" class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" data-grid="v"/>
        @endforeach
        @if ($n > 1)
            <path d="{{ $d }}" fill="none" class="stroke-blue-500" stroke-width="2"/>
        @endif
        @foreach ($points as $i => $p)
            <g>
                <title>{{ $p['label'] }}: {{ \App\Support\Money::format($p['cents'], $currency) }}</title>
                <circle cx="{{ round($xs[$i], 2) }}" cy="{{ round($ys[$i], 2) }}" r="3.5" class="fill-blue-500 stroke-white dark:stroke-gray-800" stroke-width="1"/>
                @if (isset($labels[$i]))
                    <text x="{{ round($labels[$i][1], 2) }}" y="{{ round($labels[$i][2], 2) }}" text-anchor="{{ $labels[$i][3] }}" class="fill-gray-700 dark:fill-gray-200" font-size="10" data-label="{{ $i }}">{{ $labels[$i][0] }}</text>
                @endif
                @if ($i % $every === 0)
                    <text x="{{ round($xs[$i], 2) }}" y="{{ $baseY + 14 }}" text-anchor="end" transform="rotate(-45 {{ round($xs[$i], 2) }} {{ $baseY + 14 }})" class="fill-gray-500 dark:fill-gray-400" font-size="11">{{ $p['label'] }}</text>
                @endif
            </g>
        @endforeach
    </svg>
</div>
