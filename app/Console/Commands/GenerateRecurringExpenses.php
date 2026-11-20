<?php

namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring';

    protected $description = 'Create the missing copies of repeating expenses, each one remind_days before it falls due';

    public function handle(): int
    {
        $n = 0;
        Expense::whereNotNull('frequency')->whereNull('repeated_from_id')->each(function (Expense $t) use (&$n) {
            $have = Expense::where('repeated_from_id', $t->id)->get()->map(fn ($c) => $c->incurred_on->toDateString())->all();
            // k × months from the original date, so the day is kept where the month allows it (31 Jan → 28 Feb → 31 Mar)
            for ($k = 1; ($on = $t->incurred_on->copy()->addMonthsNoOverflow($k * $t->frequency->months()))->copy()->subDays($t->remind_days)->lte(today()); $k++) {
                if (in_array($on->toDateString(), $have, true)) {
                    continue;
                }
                Expense::create([
                    ...$t->only(['expense_category_id', 'description', 'payee', 'currency_id', 'amount_cents', 'exchange_rate', 'remind_days', 'client_id', 'project_id', 'notes']),
                    'incurred_on' => $on,
                    'frequency' => null,
                    'repeated_from_id' => $t->id,
                ]);
                $n++;
            }
        });
        $this->info("Generated {$n} expense(s).");

        return self::SUCCESS;
    }
}
