<?php

namespace App\Exceptions;

use App\Models\Module;
use App\Services\ModuleBudget;

/**
 * An edit would push a module's task values (or hours) past its budget. Carries what the UI needs to offer the
 * three ways out: raise the budget, create an add-on, rebalance the other tasks. `over` and `needCap` are in the
 * module's budget units (cents, or hundredths of an hour for an hourly client).
 */
class OverBudget extends \RuntimeException
{
    public function __construct(public readonly Module $module, public readonly int $over, public readonly int $needCap, public readonly ?int $newCap = null)
    {
        parent::__construct('This puts '.$module->name.' '.(new ModuleBudget($module))->format($over).' over its budget.');
    }
}
