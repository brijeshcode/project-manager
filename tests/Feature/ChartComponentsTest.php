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
