<?php

use App\Support\Money;

it('rounds half-up to cents', function () {
    expect(Money::toCents('19.995'))->toBe(2000)
        ->and(Money::toCents('19.994'))->toBe(1999)
        ->and(Money::toCents(5))->toBe(500)
        ->and(Money::toCents(19.99))->toBe(1999)
        ->and(Money::toCents('1,980.50'))->toBe(198050)
        ->and(Money::toCents('-0.005'))->toBe(-1);
});

it('formats cents', function () {
    expect(Money::format(198000, 'USD'))->toBe('$1,980.00')
        ->and(Money::format(5, 'EUR'))->toBe('€0.05')
        ->and(Money::format(100, 'XYZ'))->toBe('XYZ 1.00');
});
