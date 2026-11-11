<?php

namespace App\Livewire\Concerns;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Label;
use App\Models\Module;
use App\Models\Task;
use App\Models\TaskType;
use Illuminate\Validation\Rule;

/**
 * A client requests new modules and tasks on $this->project. Requests wait (request_status = pending) until someone
 * who can update the project approves them; until then the client may edit or delete them, and editing a rejected
 * one sends it back for approval. Only the fields below are ever taken from the client.
 */
trait ClientRequests
{
    /** null = closed, 0 = new, else the id being edited. */
    public ?int $moduleFormId = null;

    public array $moduleForm = [];

    public ?int $taskFormId = null;

    public array $taskForm = [];

    private function guardClient(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
    }

    private function requestedModule(int $id): Module
    {
        return $this->project->modules()->where('client_visible', true)->whereNotNull('request_status')->findOrFail($id);
    }

    private function requestedTask(int $id): Task
    {
        return Task::whereIn('module_id', $this->project->modules()->select('id'))->where('creator_id', auth()->id())
            ->whereNotNull('request_status')->findOrFail($id);
    }

    public function openModuleRequest(int $id = 0): void
    {
        $this->guardClient();
        $m = $id ? $this->requestedModule($id) : null;
        $this->resetErrorBag();
        $this->moduleFormId = $id;
        $this->moduleForm = ['name' => $m?->name ?? '', 'description' => $m?->description ?? '', 'deadline' => $m?->deadline?->toDateString() ?? '', 'priority' => $m?->priority ?? 1];
    }

    public function saveModuleRequest(): void
    {
        $this->guardClient();
        $d = $this->validate([
            'moduleForm.name'        => 'required|string|max:255',
            'moduleForm.description' => 'nullable|string|max:10000',
            'moduleForm.deadline'    => 'nullable|date',
            'moduleForm.priority'    => 'required|integer|in:0,1,2',
        ])['moduleForm'];
        $data = ['name' => trim($d['name']), 'description' => trim($d['description'] ?? '') ?: null, 'deadline' => $d['deadline'] ?: null, 'priority' => (int) $d['priority'], 'request_status' => 'pending', 'request_note' => null];

        $this->moduleFormId
            ? $this->requestedModule($this->moduleFormId)->update($data)
            : Module::create($data + ['project_id' => $this->project->id, 'client_visible' => true, 'position' => Module::nextPosition($this->project->id)]);
        $this->moduleFormId = null;
    }

    public function deleteModuleRequest(int $id): void
    {
        $this->guardClient();
        $m = $this->requestedModule($id);
        abort_if($m->tasks()->exists(), 422, 'This module already has tasks.');
        $m->delete();
    }

    /** Modules a client may add tasks to: shared and approved. */
    public function requestableModules()
    {
        return $this->project->modules()->where('client_visible', true)->approved()->orderBy('position')->get(['id', 'name']);
    }

    public function openTaskRequest(int $id = 0, ?int $moduleId = null): void
    {
        $this->guardClient();
        $t = $id ? $this->requestedTask($id) : null;
        $this->resetErrorBag();
        $this->taskFormId = $id;
        $this->taskForm = [
            'module_id' => $t?->module_id ?? $moduleId ?? $this->requestableModules()->first()?->id, 'title' => $t?->title ?? '',
            'description' => $t?->description ?? '', 'priority' => $t?->priority ?? 0, 'task_type_id' => $t?->task_type_id ?? TaskType::default()?->id,
            'labels' => $t ? $t->labels()->pluck('labels.id')->all() : [], 'deadline' => $t?->deadline?->toDateString() ?? '',
        ];
    }

    public function saveTaskRequest(): void
    {
        $this->guardClient();
        $d = $this->validate([
            'taskForm.module_id'    => ['required', 'integer', Rule::in($this->requestableModules()->pluck('id')->all())],
            'taskForm.title'        => 'required|string|max:255',
            'taskForm.description'  => 'nullable|string|max:10000',
            'taskForm.priority'     => 'required|integer|in:0,1,2',
            'taskForm.task_type_id' => ['nullable', Rule::exists('task_types', 'id')],
            'taskForm.labels'       => 'array',
            'taskForm.labels.*'     => ['integer', Rule::exists('labels', 'id')],
            'taskForm.deadline'     => 'nullable|date',
        ], [], ['taskForm.module_id' => 'module'])['taskForm'];
        $data = [
            'module_id' => (int) $d['module_id'], 'title' => trim($d['title']), 'description' => trim($d['description'] ?? '') ?: null,
            'priority' => (int) $d['priority'], 'task_type_id' => $d['task_type_id'] ?: TaskType::default()?->id, 'deadline' => $d['deadline'] ?: null,
            'request_status' => 'pending', 'request_note' => null,
        ];

        if ($this->taskFormId) {
            $task = $this->requestedTask($this->taskFormId);
            $task->update($data);
            $task->syncLabels(array_map('intval', $d['labels'] ?? []));
        } else {
            $task = Task::create($data + [
                'creator_id' => auth()->id(), 'status' => TaskStatus::Published, 'scope' => TaskScope::In,
                'client_visible' => true, 'is_idea' => false, 'estimate_hours' => 0.0,
            ]);
            $task->labels()->sync(array_map('intval', $d['labels'] ?? []));
        }
        $this->taskFormId = null;
    }

    public function deleteTaskRequest(int $id): void
    {
        $this->guardClient();
        $this->requestedTask($id)->delete();
    }

    public function closeRequestForms(): void
    {
        $this->reset('moduleFormId', 'taskFormId');
    }

    protected function requestOptions(): array
    {
        return ['requestModules' => $this->taskFormId !== null ? $this->requestableModules() : collect(), 'requestTypes' => TaskType::orderBy('position')->get(), 'requestLabels' => Label::orderBy('name')->get()];
    }
}
