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

    /** Chart range + bucket size for the selected period (12 whole calendar months for 365). */
    private function revenueSeries(): array
    {
        [$from, $granularity] = match ($this->period) {
            7, 30 => [now()->subDays($this->period - 1)->startOfDay(), 'day'],
            90 => [now()->subDays(89)->startOfDay(), 'week'],
            default => [now()->subMonths(11)->startOfMonth(), 'month'],
        };

        return DashboardMoney::revenueSeriesLocal($from, now(), $granularity);
    }

    public function render()
    {
        abort_unless(auth()->user()->can('manage-money'), 403);

        $from = now()->subDays($this->period - 1)->startOfDay();

        return view('livewire.dashboard', [
            'owing' => DashboardMoney::owingClientIds(),
            'statusCounts' => DashboardMoney::invoiceStatusCounts($from, now()),
            'overdue' => DashboardMoney::overdueCount(),
            'sentThisMonth' => DashboardMoney::sentThisMonthCount(),
            'recentInvoices' => DashboardMoney::recentInvoices(),
            'upcoming' => DashboardMoney::upcomingDues(),
            'topClients' => DashboardMoney::topClients($from, now()),
            'revenue' => $this->revenueSeries(),
            'money' => DashboardMoney::forPeriodLocal(now()->subDays($this->period - 1)->startOfDay(), now()),
            'clientCount' => Client::count(),
            'activeClients' => Client::whereHas('projects', fn ($q) => $q->where('status', 'active'))->count(),
            'projectCount' => Project::count(),
            'openTasks' => Task::whereDoesntHave('column', fn ($q) => $q->where('stage', 'done'))->count(),
            'hoursWeek' => (float) TimeEntry::whereBetween('worked_on', [now()->startOfWeek(), now()->endOfWeek()])->sum('hours'),
            'recent' => Project::with('client')->latest()->take(5)->get(),
        ]);
    }
}
