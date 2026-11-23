<?php

namespace App\Livewire\Team;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Super-admin page: internal users (everyone but clients). New users get a reset link to set their own password. */
class Index extends Component
{
    public const ROLES = ['project_manager' => 'Project manager', 'developer' => 'Developer'];

    /** null = modal closed, 0 = creating, id = editing. */
    public ?int $editing = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'developer';

    public ?string $capacity = null;

    public function create(): void
    {
        $this->reset('name', 'email', 'role', 'capacity');
        $this->resetErrorBag();
        $this->editing = 0;
    }

    public function edit(int $id): void
    {
        $u = $this->internal()->findOrFail($id);
        $this->name = $u->name;
        $this->email = $u->email;
        $this->role = $u->getRoleNames()->first(fn ($r) => isset(self::ROLES[$r])) ?? 'developer';
        $this->capacity = $u->weekly_capacity_hours === null ? null : (string) $u->weekly_capacity_hours;
        $this->resetErrorBag();
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        abort_if($this->editing === null, 422);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing ?: null)],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'capacity' => ['nullable', 'numeric', 'min:0', 'max:168'],
        ]);
        $attrs = ['name' => $data['name'], 'email' => $data['email'], 'weekly_capacity_hours' => $data['capacity'] === null || $data['capacity'] === '' ? null : $data['capacity']];

        if ($this->editing) {
            $user = $this->internal()->findOrFail($this->editing);
            $user->update($attrs);
            // super_admin keeps that role; only the project_manager/developer part is swapped
            $user->syncRoles([...$user->getRoleNames()->diff(array_keys(self::ROLES))->all(), $data['role']]);
            $msg = 'Team member saved.';
        } else {
            $user = User::create([...$attrs, 'password' => Str::random(40)]);
            $user->assignRole($data['role']);
            Password::sendResetLink(['email' => $user->email]);
            $msg = 'Team member added. A link to set their password was e-mailed.';
        }
        $this->editing = null;
        $this->dispatch('toast', type: 'success', message: $msg);
    }

    public function toggleActive(int $id): void
    {
        $user = $this->internal()->findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->dispatch('toast', type: 'error', message: 'You cannot deactivate yourself.');

            return;
        }
        $user->update(['active' => ! $user->active]);
        $this->dispatch('toast', type: 'success', message: $user->name.($user->active ? ' reactivated.' : ' deactivated; they can no longer sign in.'));
    }

    private function internal()
    {
        return User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'client'));
    }

    public function render()
    {
        return view('livewire.team.index', [
            'users' => $this->internal()->with('roles')->withCount('projects')->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }
}
