<?php

namespace App\Livewire\Sprints;

use App\Models\Sprint;
use App\Models\Task;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The signed-in user's tasks across every project's active sprint. */
class Mine extends Component
{
    /** mine | all */
    #[Url]
    public string $show = 'mine';

    public function mount()
    {
        if (auth()->user()->hasRole('client')) {
            return redirect()->route('portal.dashboard');
        }
    }

    public function render()
    {
        $user = auth()->user();
        $sprints = Sprint::active()->with('project')
            ->whereHas('project', fn ($p) => $user->hasRole('super_admin') ? $p : $p->whereHas('members', fn ($m) => $m->where('users.id', $user->id)->where('project_members.active', true)))
            ->orderBy('ends_on')->get();

        $tasks = Task::whereIn('sprint_id', $sprints->pluck('id'))->counted()->visibleTo($user)
            ->when($this->show !== 'all', fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->with(['column', 'subtasks', 'module.project'])
            ->withSum('timeEntries as logged_hours', 'hours') // ponytail: own entries only, subtask time not included
            ->orderBy('position')->orderBy('id')->get()->groupBy('sprint_id');

        $groups = $sprints->map(function (Sprint $s) use ($tasks) {
            $all = $tasks->get($s->id, collect());
            $by = fn (array $stages) => $all->filter(fn (Task $t) => in_array($t->column?->stage ?? 'not_started', $stages, true))->values();

            return ['sprint' => $s, 'all' => $all, 'lanes' => ['To do' => $by(['not_started']), 'In progress' => $by(['active', 'review']), 'Done' => $by(['done'])]];
        })->filter(fn ($g) => $g['all']->isNotEmpty() || $this->show === 'all')->values();

        $done = $groups->sum(fn ($g) => $g['lanes']['Done']->count());

        return view('livewire.sprints.mine', [
            'groups' => $groups,
            'hasSprints' => $sprints->isNotEmpty(),
            'open' => $groups->sum(fn ($g) => $g['all']->count()) - $done,
            'done' => $done,
            'load' => $user->sprintLoad(),
        ]);
    }
}
