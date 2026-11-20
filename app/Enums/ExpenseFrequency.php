<?php

namespace App\Enums;

enum ExpenseFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    public function label(): string
    {
        return $this === self::HalfYearly ? 'Half-yearly' : ucfirst($this->value);
    }
}
