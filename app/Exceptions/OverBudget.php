<?php

namespace App\Exceptions;

use App\Models\Module;
use App\Services\ModuleBudget;

/**
 * A manual edit of a fixed amount would push a module's task values past its amount. `over` and `needCap` are cents:
 * needCap is the module amount that would make the edit fit (or, for a lowered module amount, the lowest allowed).
 */
class OverBudget extends \RuntimeException
{
    public function __construct(public readonly Module $module, public readonly int $over, public readonly int $needCap, public readonly ?int $newCap = null)
    {
        parent::__construct('This puts '.$module->name.' '.(new ModuleBudget($module))->format($over).' over its budget.');
    }
}
