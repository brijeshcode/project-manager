<?php

namespace App\Livewire;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\DashboardMoney;
use Livewire\Component;

class Dashboard extends Component
{
    public int $period = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-money'), 403);
    }

    public function updatedPeriod(): void
    {
        $this->period = in_array($this->period, [7, 30, 90, 365], true) ? $this->period : 30;
    }

    public function refresh(): void
    {
        abort_unless(auth()->user()->can('manage-money'), 403);
    }

    public function render()
    {
        abort_unless(auth()->user()->can('manage-money'), 403);

        return view('livewire.dashboard', [
            'money' => DashboardMoney::forPeriod(now()->subDays($this->period - 1)->startOfDay(), now()),
            'owing' => DashboardMoney::owingClients(),
            'clientCount' => Client::count(),
            'activeClients' => Client::whereHas('projects', fn ($q) => $q->where('status', 'active'))->count(),
            'projectCount' => Project::count(),
            'openTasks' => Task::where('board_column', '!=', 'Done')->count(),
            'hoursWeek' => (float) TimeEntry::whereBetween('worked_on', [now()->startOfWeek(), now()->endOfWeek()])->sum('hours'),
            'recent' => Project::with('client')->latest()->take(5)->get(),
        ]);
    }
}
