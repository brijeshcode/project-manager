<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;

class Subscriptions extends Component
{
    use WithListSearch;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        $client = auth()->user()->client;
        $like = $this->likeTerm();
        $all = $client->recurringCharges()->withUsage()
            ->with(['project' => fn ($q) => $q->where('client_id', $client->id)])
            ->orderByRaw("CASE WHEN client_state = 'awaiting' THEN 0 ELSE 1 END")->orderByDesc('active')->orderBy('next_due_on')
            ->get(['id', 'client_id', 'project_id', 'description', 'amount_cents', 'frequency', 'next_due_on', 'active', 'client_state', 'resume_on', 'skipped_periods', 'paused_periods']);
        $cur = fn ($c) => $c->project?->currency ?? $client->currency;
        $active = $all->where('active', true);

        return view('livewire.portal.subscriptions', [
            'charges' => $all
                ->when($this->status === 'active', fn ($c) => $c->where('active', true))
                ->when($this->status === 'paused', fn ($c) => $c->where('active', false))
                ->when($like, fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->description.' '.$s->project?->name), mb_strtolower(trim($this->search)))))
                ->values(),
            'client' => $client,
            'awaiting' => $all->where('client_state', \App\Models\RecurringCharge::AWAITING)->count(),
            'stats' => [
                'active' => $active->count(),
                'paused' => $all->count() - $active->count(),
                'monthly' => $active->groupBy($cur)->map->sum(fn ($c) => $c->monthlyCents())->all(),
                'due_soon' => $active->filter(fn ($c) => $c->next_due_on->lte(today()->addDays(30)))->count(),
                'invoiced' => $all->groupBy($cur)->map->sum(fn ($c) => (int) $c->invoiced_cents)->all(),
                'invoiced_count' => (int) $all->sum('invoiced_count'),
            ],
        ])->layout('layouts.app');
    }
}
