<?php

use Illuminate\Support\Facades\Blade;

function lineChart(array $cents, array $labels = []): string
{
    $points = [];
    foreach ($cents as $i => $c) {
        $points[] = ['label' => $labels[$i] ?? 'D'.$i, 'cents' => $c];
    }

    return Blade::render('<x-ui.line-chart :points="$p" currency="INR" />', ['p' => $points]);
}

it('line chart draws a dot, tooltip and vertical grid line per point, with the aria-label', function () {
    $html = lineChart([0, 123400, 5000], ['Oct 1', 'Oct 2', 'Oct 3']);

    expect(substr_count($html, '<circle'))->toBe(3)
        ->and(substr_count($html, 'data-grid="v"'))->toBe(3)
        ->and($html)->toContain('<title>Oct 2: ₹1,234.00</title>')->toContain('<title>Oct 1: ₹0.00</title>')
        ->toContain('aria-label="Revenue in INR"')->toContain('rotate(-45 ')->toContain('<path d="M')
        ->toContain('stroke-blue-500')->toContain('dark:stroke-gray-700');
});

it('line chart ticks are a nice scale covering the peak (1,234.00 -> 0 to 1,500 by 500)', function () {
    $html = lineChart([0, 123400]);

    expect(substr_count($html, 'data-grid="h"'))->toBe(4)
        ->and($html)->toContain('>0.00<')->toContain('>500.00<')->toContain('>1,000.00<')->toContain('>1,500.00<')
        ->not->toContain('2,000.00');
});

it('line chart for an all-zero series ticks 0.00 to 1.00 by 0.10', function () {
    $html = lineChart([0, 0, 0]);

    expect(substr_count($html, 'data-grid="h"'))->toBe(11);
    foreach (['0.00', '0.10', '0.50', '0.90', '1.00'] as $t) {
        expect($html)->toContain('>'.$t.'<');
    }
});

it('line chart handles a single point', function () {
    $html = lineChart([2500], ['Only']);
    expect(substr_count($html, '<circle'))->toBe(1)->and($html)->toContain('<title>Only: ₹25.00</title>');
});

function doughnut(array $c): string
{
    return Blade::render('<x-ui.doughnut :counts="$c" />', ['c' => $c]);
}

it('doughnut segments are proportional and ordered paid, pending, overdue, void', function () {
    $html = doughnut(['paid' => 3, 'pending' => 2, 'overdue' => 0, 'void' => 0]);

    expect(substr_count($html, 'pathLength="100"'))->toBe(2)
        ->and($html)->toContain('stroke-dasharray="60 40" stroke-dashoffset="0"')
        ->toContain('stroke-dasharray="40 60" stroke-dashoffset="-60"')
        ->toContain('<title>Paid: 3 (60%)</title>')->toContain('<title>Pending: 2 (40%)</title>')
        ->not->toContain('Overdue:')->not->toContain('No invoices in this period');
});

it('doughnut shows all four legend labels, and an empty state with no segments when total is 0', function () {
    $html = doughnut(['paid' => 0, 'pending' => 0, 'overdue' => 0, 'void' => 0]);

    expect($html)->toContain('No invoices in this period')->not->toContain('pathLength');
    foreach (['Paid', 'Pending', 'Overdue', 'Void'] as $l) {
        expect($html)->toContain('</span>'.$l.'</li>');
    }
});

it('line chart labels non-zero dots with compact amounts, none for zero points', function () {
    $html = lineChart([0, 123400, 1234500, 120000000, 0], ['A', 'B', 'C', 'D', 'E']);

    expect(substr_count($html, 'data-label='))->toBe(3)
        ->and($html)->toContain('>1,234<')->toContain('>12.3K<')->toContain('>1.2M<')
        ->toContain('text-anchor="middle"')->toContain('dark:fill-gray-200')
        // the full amount stays in the tooltip
        ->toContain('<title>C: ₹12,345.00</title>');
});

it('line chart always labels the peak and drops smaller overlapping neighbours', function () {
    $c = array_fill(0, 30, 0);
    $c[10] = 500000; // 5,000
    $c[11] = 400000; // 4,000, about 18 units away: overlaps the peak label
    $c[25] = 300000; // 3,000, far away: kept
    $html = lineChart($c);

    expect($html)->toContain('data-label="10">5,000<')->toContain('data-label="25">3,000<')
        ->not->toContain('>4,000<');
});

it('line chart labels a lone point and nothing for an all-zero series', function () {
    expect(lineChart([8350000]))->toContain('>83.5K<')->and(lineChart([83500]))->toContain('>835<')
        ->and(lineChart([0, 0, 0]))->not->toContain('data-label=');
});
