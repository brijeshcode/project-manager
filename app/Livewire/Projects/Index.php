<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithListSearch;
use App\Models\Project;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch;

    public function render()
    {
        $user  = auth()->user();
        $query = Project::with(['client', 'members'])->withCount('modules')->orderBy('name');

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

        return view('livewire.projects.index', [
            'projects' => $this->fitPage($query, \App\Support\Settings::pageSize()),
            'statusCounts' => $statusCounts,
        ]);
    }
}
