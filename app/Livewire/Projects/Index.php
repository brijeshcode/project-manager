<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $user  = auth()->user();
        $query = Project::with('client')->orderBy('name');

        if (! $user->hasRole('super_admin')) {
            $query->whereHas('members', fn ($q) => $q
                ->where('users.id', $user->id)
                ->where('project_members.active', true));
        }

        return view('livewire.projects.index', [
            'projects' => $query->get(),
        ]);
    }
}
