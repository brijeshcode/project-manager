<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;

class ClientAccount
{
    // Payments only for now; Task 6 subtracts non-void invoice totals.
    public function balanceCents(Client $client): int
    {
        return (int) $client->payments()->sum('amount_cents');
    }

    public function softBalanceCents(Project $project): int
    {
        $budget = $project->modules->sum(fn ($m) => $m->budgetCents() ?? 0);
        $paid = (int) Payment::where('project_id', $project->id)->sum('amount_cents');

        return max(0, $budget - $paid);
    }
}
