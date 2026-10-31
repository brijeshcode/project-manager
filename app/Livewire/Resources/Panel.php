<?php

namespace App\Livewire\Resources;

use App\Models\Project;
use App\Models\Resource;
use App\Models\ResourceItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Panel extends Component
{
    #[Locked]
    public Project $project;

    public string $newTitle = '';

    public string $newDescription = '';

    /** Keyed by resource id. */
    public array $itemLabel = [];

    public array $itemValue = [];

    /** Keyed by item id. */
    public array $assigneeIds = [];

    public function mount(Project $project): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        $this->authorize($user->hasRole('client') ? 'viewAsClient' : 'view', $project);
        $this->project = $project;
    }

    private function isAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    #[Computed]
    public function resources(): Collection
    {
        $user = auth()->user();

        return Resource::visibleTo($user)
            ->where('project_id', $this->project->id)
            ->with(['items' => fn ($q) => $q->visibleTo($user)->with(['assignees', 'creator'])])
            ->orderBy('id')
            ->get();
    }

    /** Users who may be assigned: active project members + the project's client users. */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $this->project->members()->wherePivot('active', true)->pluck('users.id'))
                ->orWhere(fn ($c) => $c->whereNotNull('client_id')->where('client_id', $this->project->client_id)))
            ->orderBy('name')
            ->get();
    }

    public function addResource(): void
    {
        abort_unless($this->isAdmin(), 403);
        $this->validate(['newTitle' => 'required|string|max:255', 'newDescription' => 'nullable|string|max:5000']);

        $this->project->resources()->create([
            'title' => $this->newTitle,
            'description' => $this->newDescription ?: null,
        ]);

        $this->reset('newTitle', 'newDescription');
        unset($this->resources);
    }

    public function addTextItem(int $resourceId): void
    {
        $user = auth()->user();
        $resource = Resource::visibleTo($user)->where('project_id', $this->project->id)->findOrFail($resourceId);

        $this->validate([
            "itemLabel.$resourceId" => 'required|string|max:255',
            "itemValue.$resourceId" => 'required|string|max:10000',
        ]);

        $item = $resource->items()->create([
            'type' => 'text',
            'label' => trim($this->itemLabel[$resourceId]),
            'value' => $this->itemValue[$resourceId],
            'created_by' => $user->id,
        ]);
        $item->assignees()->attach($user->id);

        unset($this->itemLabel[$resourceId], $this->itemValue[$resourceId]);
        unset($this->resources);
    }

    public function setAssignees(int $itemId, array $userIds): void
    {
        abort_unless($this->isAdmin(), 403);
        $item = ResourceItem::visibleTo(auth()->user())
            ->whereHas('resource', fn ($r) => $r->where('project_id', $this->project->id))
            ->findOrFail($itemId);

        $ids = collect($userIds)->map(fn ($i) => (int) $i)->unique()->values();
        if ($ids->diff($this->candidates->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['assignees' => 'Every assignee must be an active project member or a client user of this project.']);
        }

        $item->assignees()->sync($ids->all());
        unset($this->resources);
    }

    public function render()
    {
        return view('livewire.resources.panel');
    }
}
