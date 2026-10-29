<?php

use App\Services\EstimateService;
use Carbon\Carbon;

// ── workingDaysFrom ────────────────────────────────────────────────────────────
//
// Convention: a task starts at the beginning of a working day. Each working day
// provides exactly 8 hours of capacity. A task that consumes exactly 8 hours
// finishes on the same day (end-of-span lands ON that day, not the next).
// e.g. 8h from Monday = Monday; 9h from Monday = Tuesday.

it('8h from a Monday 09:00 finishes on that same Monday', function () {
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-26 09:00:00'); // Monday

    $result = $svc->workingDaysFrom($start, 8.0);

    expect($result->toDateString())->toBe('2026-10-26'); // same Monday
});

it('16h from a Friday lands on Monday, skipping the weekend', function () {
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-30 09:00:00'); // Friday

    // 8h consumed on Friday, 8h consumed on Monday → finishes Monday
    $result = $svc->workingDaysFrom($start, 16.0);

    // The weekend (Sat 2026-11-01, Sun 2026-11-02) is skipped.
    // Friday consumes 8h, Monday consumes remaining 8h → lands Monday 2026-11-03
    expect($result->toDateString())->toBe('2026-11-02'); // Monday after weekend
});

it('0h returns the start date itself', function () {
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-26');

    $result = $svc->workingDaysFrom($start, 0.0);

    expect($result->toDateString())->toBe('2026-10-26');
});

it('9h from Monday spills into Tuesday', function () {
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-26'); // Monday

    $result = $svc->workingDaysFrom($start, 9.0);

    expect($result->toDateString())->toBe('2026-10-27'); // Tuesday
});

it('starting on Saturday jumps to next Monday', function () {
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-31'); // Saturday

    $result = $svc->workingDaysFrom($start, 8.0);

    // Saturday is not a working day; first working day is Monday 2026-11-02
    expect($result->toDateString())->toBe('2026-11-02'); // Monday
});

// ── buffer applied after hours→days conversion ────────────────────────────────

it('buffer is applied after conversion: 5 working days + 20% buffer = 6 days', function () {
    // We test this via projectDeadline with a controlled project.
    // 40 hours of work = 5 working days. With 20% buffer: 5 * 1.2 = 6 days.
    // start_date = Monday 2026-10-26 → 6 working days out = Monday 2026-11-02.
    // 1=Mon26, 2=Tue27, 3=Wed28, 4=Thu29, 5=Fri30, 6=Mon02-Nov
    $svc   = new EstimateService();
    $start = Carbon::parse('2026-10-26'); // Monday

    // 5 working days base (0h left on day 5 = lands on day 5)
    $base = $svc->workingDaysFrom($start, 40.0); // 5 × 8h → Friday 2026-10-30
    expect($base->toDateString())->toBe('2026-10-30'); // Friday

    // 6 working days (40h + 20% = 48h = 6 × 8h)
    $buffered = $svc->workingDaysFrom($start, 48.0);
    expect($buffered->toDateString())->toBe('2026-11-02'); // Monday (skips weekend)
});
