<?php

namespace App\Console\Commands;

use App\Models\RecurringCharge;
use Illuminate\Console\Command;

class GenerateRecurringCharges extends Command
{
    protected $signature = 'billing:generate-recurring';

    protected $description = 'Create pending billable lines for due recurring charges';

    public function handle(): int
    {
        $resumed = RecurringCharge::resumeDue(today());
        $resumed && $this->info("Resumed {$resumed} paused service(s).");
        $n = 0;
        RecurringCharge::where('active', true)->each(function ($c) use (&$n) {
            $n += $c->generateDue(today());
        });
        $this->info("Generated {$n} line(s).");

        return self::SUCCESS;
    }
}
