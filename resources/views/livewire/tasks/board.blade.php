<div class="flex gap-4 overflow-x-auto p-4">
    @foreach($columnOrder as $col)
        <div class="flex-shrink-0 w-64">
            <h3 class="font-semibold mb-2">{{ $col->value }}</h3>
            <ul
                class="board-column min-h-16 space-y-2"
                data-column="{{ $col->value }}"
                wire:ignore.self
            >
                @foreach($columns[$col->value] as $task)
                    <li
                        class="bg-white rounded shadow p-2 cursor-grab"
                        data-task-id="{{ $task->id }}"
                    >
                        {{ $task->title }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @once
        @push('scripts')
            @vite(['resources/js/board-drag.js'])
        @endpush
    @endonce
</div>
