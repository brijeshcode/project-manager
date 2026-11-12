<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\RemembersView;
use App\Models\Project;
use App\Services\ClientAccount;
use App\Services\ProgressService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Projects extends Component
{
    use RemembersView;
    #[Url(as: 'view')]
    public string $view = 'cards';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        $this->view = in_array($this->view, ['cards', 'list'], true) ? $this->view : 'cards';
        $client = auth()->user()->client;
        $account = app(ClientAccount::class);
        $progress = app(ProgressService::class);
        // explicit columns: no cost or internal fields; final_budget is the agreed project amount
        $projects = Project::where('client_id', $client->id)->whereIn('status', Project::CLIENT_STATUSES)
            ->with('modules')->orderBy('name')
            ->get(['id', 'name', 'status', 'client_id', 'currency_id', 'deadline', 'final_budget']);

        return view('livewire.portal.projects', [
            'projects' => $projects,
            'currency' => $client->currency,
            'stats' => $projects->mapWithKeys(fn ($p) => [$p->id => $progress->forClient($p)]),
            'expected' => $projects->mapWithKeys(fn ($p) => [$p->id => $account->softBalanceCents($p)]),
        ])->layout('layouts.app');
    }
}
