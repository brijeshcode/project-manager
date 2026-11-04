{{-- One task row of a plan list. $task, $progress, $draggable. --}}
@php $done = $task->column?->stage === 'done'; @endphp
<li wire:key="sp-{{ $task->id }}" data-id="{{ $task->id }}" data-test="sprint-task" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 bg-white px-3 py-2.5 dark:bg-transparent">
    @if ($draggable && ! $done)
        <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500" title="Drag to move or reorder" aria-label="Drag to move or reorder" data-test="sprint-handle"><x-icon name="grip-vertical" /></span>
    @else
        <span class="w-4"></span>
    @endif
    <span class="w-28 shrink-0 truncate text-xs font-medium text-brand-600" title="{{ $task->module->name }}" data-test="task-module">{{ $task->module->name }}</span>
    <a href="{{ route('tasks.show', $task) }}" wire:navigate data-test="task-link" @class(['min-w-0 flex-1 basis-48 truncate text-sm font-medium hover:text-brand-600', 'text-slate-800' => ! $done, 'text-slate-400 line-through' => $done])>{{ $task->title }}</a>
    <x-ui.type-badge :type="$task->type" />
    @foreach ($task->labels as $l)<x-ui.label-chip :label="$l" class="hidden md:inline-flex" />@endforeach
    <x-ui.priority :value="$task->priority" />
    <x-ui.assignees :users="$task->assignees" :candidates="($canManage ?? false) ? $candidates : null" method="toggleTaskAssignee" :id="$task->id" />
    <span class="w-14 text-right text-xs tabular-nums text-slate-500">{{ $task->hours() ? rtrim(rtrim(number_format($task->hours(), 1), '0'), '.').'h' : '' }}</span>
    <span class="w-14 text-xs"><x-ui.deadline :date="$task->deadline" :done="$done" /></span>
    <x-ui.stage-badge :column="$task->column" />
    <x-ui.progress :pct="$progress->forTask($task)" :label="false" class="w-20" />
</li>
