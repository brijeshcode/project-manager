<?php

namespace App\Livewire\Time;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Logger extends Component
{
    public string $timeableType = '';
    public int    $timeableId   = 0;

    public float  $hours     = 0.0;
    public string $worked_on = '';
    public ?string $started_at   = null;
    public ?string $completed_at = null;

    public function mount(Model $timeable): void
    {
        $this->timeableType = get_class($timeable);
        $this->timeableId   = $timeable->id;
        $this->worked_on    = now()->toDateString();
    }

    public function log(): void
    {
        $this->validate([
            'hours'      => 'required|numeric|min:0.01',
            'worked_on'  => 'required|date',
        ]);

        // Allowlist to prevent arbitrary model injection via tampered properties.
        $allowed = [Task::class, \App\Models\Subtask::class];
        abort_unless(in_array($this->timeableType, $allowed, true), 403);

        $timeable = $this->timeableType::find($this->timeableId);
        abort_unless($timeable, 403);

        // Resolve the task (for subtasks, check the parent).
        $task = $timeable instanceof Task ? $timeable : $timeable->task;
        $this->authorize('comment', $task);

        TimeEntry::create([
            'timeable_type' => $this->timeableType,
            'timeable_id'   => $this->timeableId,
            'user_id'       => auth()->id(),
            'hours'         => $this->hours,
            'worked_on'     => $this->worked_on,
            'started_at'    => $this->started_at ?: null,
            'completed_at'  => $this->completed_at ?: null,
        ]);

        $this->hours        = 0.0;
        $this->started_at   = null;
        $this->completed_at = null;
    }

    public function render()
    {
        return view('livewire.time.logger', [
            'entries' => TimeEntry::where('timeable_type', $this->timeableType)
                ->where('timeable_id', $this->timeableId)
                ->orderByDesc('worked_on')
                ->get(),
        ]);
    }
}
