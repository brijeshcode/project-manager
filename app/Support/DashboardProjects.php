<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Services\LocalCurrency;

/** Project-tab data for the dashboard, money in local-currency cents. */
class DashboardProjects
{
    private static function open()
    {
        return Task::whereDoesntHave('column', fn ($q) => $q->where('stage', 'done'));
    }

    /** Active projects: budget, paid, completion %, on-track label, and work done but not yet paid (budget × completion − paid, never below 0). */
    public static function rows(): array
    {
        $paid = Payment::whereNotNull('project_id')->where('local_currency', LocalCurrency::code())
            ->groupBy('project_id')->selectRaw('project_id, SUM(local_amount_cents) as s')->pluck('s', 'project_id');
        $total = Task::groupBy('project_id')->selectRaw('project_id, COUNT(*) as n')->pluck('n', 'project_id');
        $done = Task::whereHas('column', fn ($q) => $q->where('stage', 'done'))->groupBy('project_id')->selectRaw('project_id, COUNT(*) as n')->pluck('n', 'project_id');

        return Project::with('client')->where('status', 'active')->orderBy('deadline')->get()->map(function (Project $p) use ($paid, $total, $done) {
            $pct = ($t = (int) ($total[$p->id] ?? 0)) > 0 ? (int) round((int) ($done[$p->id] ?? 0) * 100 / $t) : 0;
            $budget = Money::toCents($p->final_budget_local ?? 0);
            $got = (int) ($paid[$p->id] ?? 0);

            return ['project' => $p, 'budget' => $budget, 'paid' => $got, 'pct' => $pct, 'tasks' => $t, 'status' => $p->deadlineStatus(),
                'unpaid' => max(0, (int) round($budget * $pct / 100) - $got)];
        })->all();
    }

    public static function priorityTasks(int $limit = 8)
    {
        return self::open()->with('project')->where('priority', '>=', 2)->orderBy('deadline')->limit($limit)->get();
    }

    /** Open tasks, highest priority then earliest deadline. */
    public static function taskList(int $limit = 10)
    {
        return self::open()->with('project')->orderByDesc('priority')->orderBy('deadline')->limit($limit)->get();
    }

    /** Done tasks the client has not approved yet, grouped by client name. */
    public static function unapprovedByClient()
    {
        return Task::with('project.client')->whereHas('column', fn ($q) => $q->where('stage', 'done'))
            ->where(fn ($q) => $q->whereNull('client_approval')->orWhere('client_approval', '!=', 'approved'))
            ->get()->groupBy(fn ($t) => $t->project?->client?->name ?? '—')->sortKeys();
    }
}
