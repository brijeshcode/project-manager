<?php

namespace App\Enums;

enum AccessScope: string
{
    case All      = 'all';
    case Frontend = 'frontend';
    case Backend  = 'backend';
}
