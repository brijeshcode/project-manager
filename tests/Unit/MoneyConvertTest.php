<?php

use App\Support\Money;

/** Independent exact decimal product via schoolbook string multiplication, half-up to integer. */
function exactHalfUp(string $a, string $b): string
{
    [$ai, $af] = array_pad(explode('.', $a), 2, '');
    [$bi, $bf] = array_pad(explode('.', $b), 2, '');
    $x = $ai.$af;
    $y = $bi.$bf;
    $scale = strlen($af) + strlen($bf);
    $res = array_fill(0, strlen($x) + strlen($y), 0);
    for ($i = strlen($x) - 1; $i >= 0; $i--) {
        for ($j = strlen($y) - 1; $j >= 0; $j--) {
            $res[$i + $j + 1] += (int) $x[$i] * (int) $y[$j];
        }
    }
    for ($k = count($res) - 1; $k > 0; $k--) {
        $res[$k - 1] += intdiv($res[$k], 10);
        $res[$k] %= 10;
    }
    $s = str_pad(implode('', $res), $scale + 1, '0', STR_PAD_LEFT);
    $int = ltrim(substr($s, 0, strlen($s) - $scale), '0') ?: '0';
    $first = $scale > 0 ? (int) $s[strlen($s) - $scale] : 0;
    if ($first >= 5) {
        $i = strlen($int) - 1;
        while ($i >= 0 && $int[$i] === '9') {
            $int[$i--] = '0';
        }
        $int = $i < 0 ? '1'.$int : substr_replace($int, (string) ((int) $int[$i] + 1), $i, 1);
    }

    return $int;
}

it('converts and rounds half-up', function () {
    expect(Money::convert(10000, '83'))->toBe(830000)
        ->and(Money::convert(1, '0.5'))->toBe(1)
        ->and(Money::convert(1, '0.4'))->toBe(0)
        ->and(Money::convert(3, '0.5'))->toBe(2)
        ->and(Money::convert(100, '0.012345'))->toBe(1)
        ->and(Money::convert(-3, '0.5'))->toBe(-2);
});

it('is exact for large amounts', function () {
    expect(exactHalfUp('1', '0.5'))->toBe('1')->and(exactHalfUp('3', '0.5'))->toBe('2');
    $r = Money::convert(999999999, '999999.999999');
    expect((string) $r)->toBe(exactHalfUp('999999999', '999999.999999'))
        ->and($r)->toBeLessThan(PHP_INT_MAX);
    expect((string) Money::convert(123456789012, '98765.432101'))->toBe(exactHalfUp('123456789012', '98765.432101'));
});

it('guards overflow', function () {
    Money::convert(PHP_INT_MAX, '999999.999999');
})->throws(InvalidArgumentException::class);

dataset('badRates', ['0', '-1', '1e5', '.5', '1.1234567', '1234567', '', 'abc', '0.000000']);

it('rejects invalid rates', function (string $rate) {
    expect(Money::isValidRate($rate))->toBeFalse();
    Money::convert(100, $rate);
})->with('badRates')->throws(InvalidArgumentException::class);

it('accepts valid rates', function () {
    expect(Money::isValidRate('83'))->toBeTrue()->and(Money::isValidRate('0.000001'))->toBeTrue()
        ->and(Money::isValidRate('999999.999999'))->toBeTrue();
});
