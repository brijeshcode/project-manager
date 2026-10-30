<div>
    <div class="mb-5 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', $project) }}" wire:navigate
               class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
                &larr; Workspace
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-sm font-semibold text-slate-700">Board</span>
        </div>
        <span class="text-sm font-semibold text-slate-700">{{ $project->name }}</span>
    </div>

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach($columnOrder as $col)
            <div class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-100 p-3">
                {{-- Column header --}}
                <div class="mb-3 flex items-center justify-between px-1">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $col->value }}</h3>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-500">
                        {{ count($columns[$col->value]) }}
                    </span>
                </div>

                {{-- Drop zone --}}
                <ul class="board-column min-h-20 space-y-2"
                    data-column="{{ $col->value }}"
                    wire:ignore.self>
                    @foreach($columns[$col->value] as $task)
                        <li class="group rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-900/5 cursor-grab active:cursor-grabbing"
                            data-task-id="{{ $task->id }}">
                            <p class="text-sm font-medium text-slate-800">{{ $task->title }}</p>
                            @if($task->estimate_hours)
                                <p class="mt-1 text-xs text-slate-400">{{ $task->estimate_hours }}h</p>
                            @endif
                            <div class="mt-2 flex items-center gap-2">
                                @if($task->client_visible)
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">Client</span>
                                @endif
                                <a href="{{ route('tasks.edit', $task) }}" wire:navigate
                                   class="ml-auto text-xs font-medium text-brand-600 opacity-0 group-hover:opacity-100">
                                    Edit
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    @once
        @push('scripts')
            @vite(['resources/js/board-drag.js'])
        @endpush
    @endonce
</div>
