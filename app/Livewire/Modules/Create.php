<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\HandlesMarkdownUploads;
use App\Models\Module;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Own page for adding a module; `?copy={module}` prefills it from a module of the same project. */
class Create extends Component
{
    use HandlesMarkdownUploads;

    #[Locked]
    public Project $project;

    #[Locked]
    public ?int $copyFrom = null;

    public string $name = '';

    public string $description = '';

    public bool $copyTasks = true;

    protected function uploadTarget(): Model
    {
        abort(403); // the module does not exist yet; attach files on its page
    }

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        $this->project = $project;

        if ($id = request()->query('copy')) {
            $src = $project->modules()->findOrFail($id); // other projects' modules: 404
            $this->copyFrom = $src->id;
            $this->name = 'Copy of '.$src->name;
            $this->description = $src->description ?? '';
        }
    }

    public function save()
    {
        $this->authorize('update', $this->project);
        $this->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:20000']);
        $user = auth()->user();
        $attrs = [
            'project_id' => $this->project->id,
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'position' => Module::nextPosition($this->project->id),
        ];
        $src = $this->copyFrom ? $this->project->modules()->find($this->copyFrom) : null;
        if ($src) {
            $attrs += ['deadline' => $src->deadline, 'client_visible' => $src->client_visible];
            if ($user->can('manage-money')) {
                $attrs += $src->only(['budget_hours', 'hourly_rate', 'budget_amount']);
            }
        }

        $module = Module::create($attrs);

        if ($src) {
            $module->assignees()->sync($src->assignees()->pluck('users.id')->all());
            $module->labels()->sync($src->labels()->pluck('labels.id')->all());
            if ($this->copyTasks) {
                foreach ($src->tasks()->visibleTo($user)->orderBy('position')->get() as $t) {
                    $t->copyTo($module->id, $user, true);
                }
            }
        }

        return $this->redirect(route('modules.show', [$this->project, $module]), navigate: true);
    }

    public function render()
    {
        $src = $this->copyFrom ? $this->project->modules()->find($this->copyFrom) : null;

        return view('livewire.modules.create', [
            'taskCount' => $src ? $src->tasks()->visibleTo(auth()->user())->count() : 0,
        ]);
    }
}
