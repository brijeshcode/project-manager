<?php

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRates;
use Illuminate\Validation\ValidationException;

it('stores uppercased active rate and keeps history insert-only', function () {
    $u = User::factory()->create();
    $svc = new ExchangeRates;
    $first = $svc->setRate('usd', '83.5', $u);
    expect($first->currency)->toBe('USD')->and($first->is_active)->toBeTrue();
    $stamp = $first->fresh()->updated_at;
    $this->travel(5)->minutes();
    $svc->setRate('EUR', '90', $u);
    $svc->setRate('USD', '84', $u);

    expect(ExchangeRate::where('currency', 'USD')->where('is_active', true)->count())->toBe(1)
        ->and($svc->active('USD'))->toBe('84.000000')
        ->and($svc->active('EUR'))->toBe('90.000000')
        ->and($svc->active('GBP'))->toBeNull();
    $old = $first->fresh();
    expect($old->is_active)->toBeFalse()->and($old->rate)->toBe('83.500000')
        ->and($old->updated_at->equalTo($stamp))->toBeTrue();
    $h = $svc->history('USD');
    expect($h)->toHaveCount(2)->and($h[0]->rate)->toBe('84.000000')->and($h[0]->creator->id)->toBe($u->id)
        ->and($svc->activeAll())->toHaveCount(2);
});

it('rejects invalid input without writing', function () {
    $u = User::factory()->create();
    foreach ([['USD', '0'], ['USD', '1e3'], ['US', '5'], ['U1D', '5']] as [$c, $r]) {
        expect(fn () => (new ExchangeRates)->setRate($c, $r, $u))->toThrow(ValidationException::class);
    }
    expect(ExchangeRate::count())->toBe(0);
});
