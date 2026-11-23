<?php

namespace App\Livewire\Projects;

use App\Enums\AccessScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Project page "Members" card. Editors add internal users, change role/scope, deactivate or remove; the pivot row is kept so task history stays. */
class Members extends Component
{
    public const ROLES = ['project_manager' => 'Project manager', 'developer' => 'Developer'];

    #[Locked]
    public Project $project;

    public ?int $userId = null;

    public string $role = 'developer';

    public string $scope = 'all';

    public function add(): void
    {
        $this->authorize('update', $this->project);
        $this->validate([
            'userId' => ['required', Rule::in($this->candidates()->pluck('id')->all())],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'scope' => ['required', Rule::enum(AccessScope::class)],
        ]);
        $this->project->members()->syncWithoutDetaching([$this->userId => ['role_on_project' => $this->role, 'access_scope' => $this->scope, 'active' => true, 'removed_at' => null]]);
        $this->reset('userId');
        $this->dispatch('toast', type: 'success', message: 'Member added.');
    }

    public function change(int $userId, string $field, string $value): void
    {
        $this->authorize('update', $this->project);
        $ok = match ($field) {
            'role_on_project' => isset(self::ROLES[$value]),
            'access_scope' => AccessScope::tryFrom($value) !== null,
            default => false,
        };
        abort_unless($ok, 422);
        $this->project->members()->updateExistingPivot($userId, [$field => $value]);
    }

    public function toggle(int $userId): void
    {
        $this->authorize('update', $this->project);
        $m = $this->project->members()->findOrFail($userId);
        $this->project->members()->updateExistingPivot($userId, ['active' => ! $m->pivot->active]);
    }

    /** Hides the member and blocks access; assignments and history stay. */
    public function remove(int $userId): void
    {
        $this->authorize('update', $this->project);
        $this->project->members()->updateExistingPivot($userId, ['active' => false, 'removed_at' => now()]);
        $this->dispatch('toast', type: 'success', message: 'Member removed.');
    }

    private function candidates()
    {
        $current = $this->project->members()->wherePivotNull('removed_at')->pluck('users.id');

        return User::where('active', true)->whereDoesntHave('roles', fn ($q) => $q->where('name', 'client'))
            ->whereNotIn('id', $current)->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        $canEdit = auth()->user()->can('update', $this->project);

        return view('livewire.projects.members', [
            'members' => $this->project->members()->wherePivotNull('removed_at')->orderByPivot('active', 'desc')->orderBy('name')->get(),
            'canEdit' => $canEdit,
            'candidates' => $canEdit ? $this->candidates() : collect(),
        ]);
    }
}
