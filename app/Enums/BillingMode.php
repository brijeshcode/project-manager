<?php

namespace App\Enums;

enum BillingMode: string
{
    case Fixed  = 'fixed';
    case Hourly = 'hourly';
}
