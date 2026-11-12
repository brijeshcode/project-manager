<?php

namespace App\Livewire\Portal;

use Livewire\Component;

class Subscriptions extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        $client = auth()->user()->client;
        $charges = $client->recurringCharges()->withUsage()
            ->with(['project' => fn ($q) => $q->where('client_id', $client->id)])
            ->orderByRaw("CASE WHEN client_state = 'awaiting' THEN 0 ELSE 1 END")->orderByDesc('active')->orderBy('next_due_on')
            ->get(['id', 'client_id', 'project_id', 'description', 'amount_cents', 'frequency', 'next_due_on', 'active', 'client_state', 'resume_on']);

        return view('livewire.portal.subscriptions', ['charges' => $charges, 'client' => $client])->layout('layouts.app');
    }
}
