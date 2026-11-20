<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\RemembersView;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use RemembersView;
    use WithListSearch;

    /** cards | list */
    #[Url(as: 'view')]
    public string $view = 'cards';

    /** Opens on active projects; ?status= (empty for All) overrides. */
    public function mount(): void
    {
        $this->status = (string) request()->query('status', 'active');
    }

    /**
     * Last day anyone worked on each project: the latest time entry on its tasks or subtasks,
     * else the latest task change. Three grouped queries, whatever the number of projects.
     *
     * @return array<int, Carbon> project id => date
     */
    public static function lastWorked(iterable $projectIds): array
    {
        $ids = collect($projectIds)->all();
        if (! $ids) {
            return [];
        }
        $onTasks = TimeEntry::where('timeable_type', (new Task)->getMorphClass())
            ->join('tasks', 'tasks.id', '=', 'time_entries.timeable_id')
            ->whereIn('tasks.project_id', $ids)->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id as p, max(time_entries.worked_on) as d')->pluck('d', 'p');
        $onSubtasks = TimeEntry::where('timeable_type', (new Subtask)->getMorphClass())
            ->join('subtasks', 'subtasks.id', '=', 'time_entries.timeable_id')
            ->join('tasks', 'tasks.id', '=', 'subtasks.task_id')
            ->whereIn('tasks.project_id', $ids)->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id as p, max(time_entries.worked_on) as d')->pluck('d', 'p');
        $touched = Task::whereIn('project_id', $ids)->groupBy('project_id')
            ->selectRaw('project_id as p, max(updated_at) as d')->pluck('d', 'p');

        $out = [];
        foreach ($ids as $id) {
            $logged = max((string) ($onTasks[$id] ?? ''), (string) ($onSubtasks[$id] ?? ''));
            $d = $logged !== '' ? $logged : ($touched[$id] ?? null);
            if ($d) {
                $out[$id] = Carbon::parse($d);
            }
        }

        return $out;
    }

    public function render()
    {
        $this->view = in_array($this->view, ['cards', 'list'], true) ? $this->view : 'cards';
        $user  = auth()->user();
        $query = Project::with(['client', 'members'])->withCount('modules')->orderByRaw("CASE WHEN projects.status = 'active' THEN 0 ELSE 1 END")->orderBy('name');

        if (! $user->hasRole('super_admin')) {
            $query->whereHas('members', fn ($q) => $q
                ->where('users.id', $user->id)
                ->where('project_members.active', true));
        }

        $statusCounts = (clone $query)->reorder()->toBase()->select('projects.status')->selectRaw('count(*) as n')->groupBy('projects.status')->pluck('n', 'status');

        $like = $this->likeTerm();
        $query->when($like, fn ($q) => $q->where(fn ($w) => $w
            ->whereRaw("projects.name LIKE ? ESCAPE '!'", [$like])
            ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
            ->when($this->status !== '', fn ($q) => $q->where('projects.status', $this->status));

        $projects = $this->fitPage($query, \App\Support\Settings::pageSize());

        return view('livewire.projects.index', [
            'projects' => $projects,
            'statusCounts' => $statusCounts,
            'lastWorked' => self::lastWorked($projects->getCollection()->whereIn('status', ['on_hold', 'draft'])->pluck('id')),
        ]);
    }
}
