<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Module;
use App\Models\Task;
use Livewire\Component;

class Form extends Component
{
    public ?int $taskId = null;

    public int    $module_id       = 0;
    public string $title           = '';
    public string $description     = '';
    public string $status          = 'draft';
    public bool   $is_idea         = false;
    public int    $priority        = 0;
    public float  $estimate_hours  = 0.0;
    public string $scope           = 'in';
    public ?float $price           = null;
    public bool   $client_visible  = false;
    public bool   $testing_enabled = false;
    public ?string $testing_add_type  = null;
    public ?float  $testing_add_value = null;
    public string $board_column    = 'Backlog';
    public int    $position        = 0;

    public function mount(?Task $task = null): void
    {
        if ($task && $task->exists) {
            $this->authorize('update', $task);
            $this->taskId           = $task->id;
            $this->module_id        = $task->module_id;
            $this->title            = $task->title;
            $this->description      = $task->description ?? '';
            $this->status           = $task->status->value;
            $this->is_idea          = $task->is_idea;
            $this->priority         = $task->priority;
            $this->estimate_hours   = $task->estimate_hours;
            $this->scope            = $task->scope->value;
            $this->price            = $task->price;
            $this->client_visible   = $task->client_visible;
            $this->testing_enabled  = $task->testing_enabled;
            $this->testing_add_type  = $task->testing_add_type;
            $this->testing_add_value = $task->testing_add_value;
            $this->board_column     = $task->board_column;
            $this->position         = $task->position;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'module_id'         => 'required|exists:modules,id',
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'status'            => 'required|in:draft,published',
            'is_idea'           => 'boolean',
            'priority'          => 'integer|min:0',
            'estimate_hours'    => 'numeric|min:0',
            'scope'             => 'required|in:in,out',
            'price'             => 'nullable|numeric|min:0',
            'client_visible'    => 'boolean',
            'testing_enabled'   => 'boolean',
            'testing_add_type'  => 'nullable|in:percent,fixed',
            'testing_add_value' => 'nullable|numeric|min:0',
            'board_column'      => 'required|string|max:100',
            'position'          => 'integer|min:0',
        ]);

        if ($this->taskId) {
            $task = Task::findOrFail($this->taskId);
            $this->authorize('update', $task);
            $task->update($data);
        } else {
            $module = Module::findOrFail($this->module_id);
            $this->authorize('update', $module->project);
            $data['creator_id'] = auth()->id();
            Task::create($data);
        }
    }

    public function render()
    {
        return view('livewire.tasks.form');
    }
}
