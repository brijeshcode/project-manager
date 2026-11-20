<?php

namespace App\Support;

class Periods
{
    /** Date shortcuts as [from, to] (Y-m-d). ponytail: financial year fixed to April–March; make it a setting if other clients need another start. */
    public static function ranges(): array
    {
        $fy = today()->month >= 4 ? today()->setDate(today()->year, 4, 1) : today()->setDate(today()->year - 1, 4, 1);

        return array_map(fn ($r) => array_map(fn ($d) => $d->toDateString(), $r), [
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'year' => [today()->startOfYear(), today()->endOfYear()],
            'fy' => [$fy, $fy->copy()->addYear()->subDay()],
        ]);
    }
}
