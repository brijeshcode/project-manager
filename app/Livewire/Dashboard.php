<?php

namespace App\Livewire;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\DashboardMoney;
use App\Support\DashboardProjects;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Component;

class Dashboard extends Component
{
    public const RANGES = ['last_week' => 'Last Week', 'last_month' => 'Last Month', 'this_month' => 'This Month', 'last_quarter' => 'Last Quarter', 'last_year' => 'Last Year', 'all' => 'All'];

    public const TREND_BY = ['week' => 'Weekly', 'month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Yearly'];

    public const TREND_COUNTS = [5, 10, 20, 50];

    public string $tab = 'financial';

    /** Financial year for the trend (start year, '' = none); FYs run April to March. */
    public string $trendFy = '';

    public string $range = 'this_month';

    public string $from = '';

    public string $to = '';

    public string $trendBy = 'month';

    public int $trendCount = 10;

    public string $trendChart = 'bar';

    public string $trendFrom = '';

    public string $trendTo = '';

    /** True once trend dates were applied; picking a count goes back to "last N". */
    public bool $trendCustom = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-money'), 403);
        $this->tab = Settings::get('dashboard_default_tab', 'financial') === 'project' ? 'project' : 'financial';
    }

    public function updatedTab(): void
    {
        $this->tab = $this->tab === 'project' ? 'project' : 'financial';
    }

    public function updatedTrendFy(): void
    {
        $this->trendFy = in_array($this->trendFy, $this->fyYears(), true) ? $this->trendFy : '';
        $this->trendCustom = false;
    }

    /** Start years of the last 6 financial years, newest first. */
    public function fyYears(): array
    {
        $y = now()->month >= 4 ? now()->year : now()->year - 1;

        return array_map('strval', range($y, $y - 5));
    }

    public function updatedRange(): void
    {
        $this->range = isset(self::RANGES[$this->range]) ? $this->range : 'this_month';
    }

    public function setRange(string $range): void
    {
        $this->range = $range;
        $this->updatedRange();
        $this->resetValidation();
    }

    public function applyRange(): void
    {
        $this->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $this->range = 'custom';
    }

    public function updatedTrendBy(): void
    {
        $this->trendBy = isset(self::TREND_BY[$this->trendBy]) ? $this->trendBy : 'month';
    }

    public function updatedTrendCount(): void
    {
        $this->trendCount = in_array($this->trendCount, self::TREND_COUNTS, true) ? $this->trendCount : 10;
        $this->trendCustom = false;
        $this->trendFy = '';
    }

    public function applyTrendRange(): void
    {
        $this->validate(['trendFrom' => 'required|date', 'trendTo' => 'required|date|after_or_equal:trendFrom'], [], ['trendFrom' => 'from', 'trendTo' => 'to']);
        $this->trendCustom = true;
        $this->trendFy = '';
    }

    public function updatedTrendChart(): void
    {
        $this->trendChart = $this->trendChart === 'line' ? 'line' : 'bar';
    }

    public function refresh(): void
    {
        abort_unless(auth()->user()->can('manage-money'), 403);
    }

    /** [from, to, label] for the selected range. */
    private function bounds(): array
    {
        $t = today();

        return match ($this->range) {
            'last_week' => [$t->copy()->subWeek()->startOfWeek(), $t->copy()->subWeek()->endOfWeek(), 'last week'],
            'last_month' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth(), 'last month'],
            'last_quarter' => [$t->copy()->subQuarterNoOverflow()->startOfQuarter(), $t->copy()->subQuarterNoOverflow()->endOfQuarter(), 'last quarter'],
            'last_year' => [$t->copy()->subYear()->startOfYear(), $t->copy()->subYear()->endOfYear(), 'last year'],
            'all' => [Carbon::create(1970), now(), 'all time'],
            'custom' => [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay(), Carbon::parse($this->from)->format('M j, Y').' – '.Carbon::parse($this->to)->format('M j, Y')],
            default => [$t->copy()->startOfMonth(), now(), 'this month'],
        };
    }

    /** Applied trend dates, else the last $trendCount buckets up to now; independent of the range filter. */
    private function trendSeries(): array
    {
        if ($this->trendFy !== '') {
            $start = Carbon::create((int) $this->trendFy, 4, 1);

            return DashboardMoney::revenueSeriesLocal($start, $start->copy()->addYear()->subDay()->endOfDay(), $this->trendBy);
        }
        if ($this->trendCustom) {
            return DashboardMoney::revenueSeriesLocal(Carbon::parse($this->trendFrom)->startOfDay(), Carbon::parse($this->trendTo)->endOfDay(), $this->trendBy);
        }

        $n = $this->trendCount - 1;
        $from = match ($this->trendBy) {
            'week' => now()->startOfWeek()->subWeeks($n),
            'quarter' => now()->startOfQuarter()->subQuarters($n),
            'year' => now()->startOfYear()->subYears($n),
            default => now()->startOfMonth()->subMonths($n),
        };

        return DashboardMoney::revenueSeriesLocal($from, now(), $this->trendBy);
    }

    public function render()
    {
        abort_unless(auth()->user()->can('manage-money'), 403);

        [$from, $to, $rangeLabel] = $this->bounds();

        $project = $this->tab === 'project';

        return view('livewire.dashboard', [
            'fyYears' => $this->fyYears(),
            'ranking' => DashboardMoney::clientRanking(),
            'statusAmounts' => DashboardMoney::paymentStatusAmounts($from, $to),
            'expenseCats' => DashboardMoney::expensesByCategory($from, $to),
            'projectRows' => $project ? DashboardProjects::rows() : [],
            'priorityTasks' => $project ? DashboardProjects::priorityTasks() : collect(),
            'taskList' => $project ? DashboardProjects::taskList() : collect(),
            'unapproved' => $project ? DashboardProjects::unapprovedByClient() : collect(),
            'rangeLabel' => $rangeLabel,
            'owing' => DashboardMoney::owingClientIds(),
            'statusCounts' => DashboardMoney::invoiceStatusCounts($from, $to),
            'overdue' => DashboardMoney::overdueCount(),
            'sentThisMonth' => DashboardMoney::sentThisMonthCount(),
            'recentInvoices' => DashboardMoney::recentInvoices(),
            'upcoming' => DashboardMoney::upcomingDues(),
            'topClients' => DashboardMoney::topClients($from, $to),
            'revenue' => $this->trendSeries(),
            'money' => DashboardMoney::forPeriodLocal($from, $to),
            'clientCount' => Client::billable()->count(),
            'activeClients' => Client::billable()->whereHas('projects', fn ($q) => $q->where('status', 'active'))->count(),
            'projectCount' => Project::count(),
            'openTasks' => Task::whereDoesntHave('column', fn ($q) => $q->where('stage', 'done'))->count(),
            'hoursWeek' => (float) TimeEntry::whereBetween('worked_on', [now()->startOfWeek(), now()->endOfWeek()])->sum('hours'),
            'recent' => Project::with('client')->latest()->take(5)->get(),
            // PHP-side date math: MySQL locally, SQLite in tests
            'bills' => Expense::due()->with('category', 'payments')->orderBy('incurred_on')->get()->filter->remindable()->values(),
        ]);
    }
}
