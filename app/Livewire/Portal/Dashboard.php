<?php

namespace App\Livewire\Portal;

use App\Enums\TaskStatus;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Services\ClientAccount;
use App\Services\ProgressService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Dashboard extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
    }

    /**
     * Projects of this client user's client that the portal shows — never another client's.
     * ponytail: scoped by client_id FK, no cost/price fields selected.
     */
    #[Computed]
    public function projects(): Collection
    {
        $clientId = auth()->user()->client_id;

        abort_if(! $clientId, 403);

        return Project::where('client_id', $clientId)
            ->whereIn('status', Project::CLIENT_STATUSES)
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'status', 'client_id', 'currency_id', 'deadline']);
    }

    /** Published, client-visible, approved tasks of the client-visible approved modules of these projects; safe columns only. */
    private function visibleTasks(Collection $projects)
    {
        return Task::whereIn('module_id', Module::whereIn('project_id', $projects->pluck('id'))->where('client_visible', true)->approved()->select('id'))
            ->where('status', TaskStatus::Published->value)->where('client_visible', true)->approved()
            ->select(['id', 'project_id', 'number', 'module_id', 'title', 'board_column_id', 'deadline', 'client_approval']);
    }

    public function render()
    {
        $account = app(ClientAccount::class);
        $progress = app(ProgressService::class);
        $client = auth()->user()->client;
        $projects = $this->projects;
        $projects->load('modules');
        foreach ($projects as $p) {
            $p->setRelation('client', $client);
            foreach ($p->modules as $m) {
                $m->setRelation('project', $p);
            }
        }
        $stats = $projects->mapWithKeys(fn ($p) => [$p->id => $progress->forClient($p)]);
        $byId = $projects->keyBy('id');
        $withProject = fn ($tasks) => $tasks->each(fn ($t) => $t->setRelation('project', $byId[$t->project_id]));

        $approvals = $withProject($this->visibleTasks($projects)
            ->whereHas('column', fn ($q) => $q->where('stage', 'done'))
            ->where(fn ($q) => $q->whereNull('client_approval')->orWhere('client_approval', '!=', 'approved'))
            ->latest('updated_at')->take(8)->get());

        $soon = [today(), today()->addDays(30)];
        $deadlines = $withProject($this->visibleTasks($projects)
            ->whereBetween('deadline', $soon)
            ->whereDoesntHave('column', fn ($q) => $q->where('stage', 'done'))
            ->orderBy('deadline')->take(8)->get())
            ->map(fn ($t) => ['date' => $t->deadline, 'title' => $t->title, 'task' => $t, 'project' => $t->project])
            ->concat($projects->flatMap->modules
                ->filter(fn ($m) => $m->client_visible && $m->request_status === null && $m->deadline && $m->deadline->between(...$soon) && $m->status() !== 'completed')
                ->map(fn ($m) => ['date' => $m->deadline, 'title' => $m->name, 'task' => null, 'project' => $m->project]))
            ->sortBy('date')->take(8)->values();

        return view('livewire.portal.dashboard', [
            'projects' => $projects,
            'stats' => $stats,
            'balance' => $account->balanceCents($client),
            'currency' => $client->currency,
            'expected' => $projects->mapWithKeys(fn ($p) => [$p->id => $account->softBalanceCents($p)]),
            'activeSubs' => $client->recurringCharges()->where('active', true)->count(),
            'approvals' => $approvals,
            'deadlines' => $deadlines,
            'invoices' => Invoice::where('client_id', $client->id)->where('status', 'sent')->latest('issued_on')->latest('id')->take(5)
                ->get(['id', 'number', 'client_id', 'issued_on', 'currency_id', 'total_cents']),
            'payments' => Payment::where('client_id', $client->id)->latest('paid_on')->latest('id')->take(5)
                ->get(['id', 'client_id', 'currency_id', 'amount_cents', 'paid_on', 'type']),
        ])->layout('layouts.app');
    }
}
