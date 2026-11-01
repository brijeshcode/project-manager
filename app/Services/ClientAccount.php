<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;

class ClientAccount
{
    // payments received minus everything invoiced (non-void); positive = client has credit
    public function balanceCents(Client $client): int
    {
        return (int) $client->payments()->sum('amount_cents')
            - (int) Invoice::where('client_id', $client->id)->where('status', 'sent')->sum('total_cents');
    }

    public function softBalanceCents(Project $project): int
    {
        $budget = $project->modules->sum(fn ($m) => $m->budgetCents() ?? 0);
        $paid = (int) Payment::where('project_id', $project->id)->where('client_id', $project->client_id)->sum('amount_cents');

        return max(0, $budget - $paid);
    }
}
