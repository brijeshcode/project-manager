<div>
    <h1 class="text-2xl font-bold mb-4">{{ $project->name }}</h1>

    {{-- Create task form --}}
    <form wire:submit="createTask" class="mb-6 flex gap-2">
        <input wire:model="newTitle"
               type="text"
               placeholder="Request a new task…"
               class="border rounded px-3 py-1 flex-1" />
        <button type="submit" class="bg-blue-600 text-white px-4 py-1 rounded">Add</button>
    </form>

    {{-- Task list --}}
    <ul class="space-y-4">
        @foreach($tasks as $task)
            <li class="border rounded p-4" data-task-id="{{ $task->id }}">
                <div class="flex justify-between items-start">
                    <h3 class="font-semibold">{{ $task->title }}</h3>
                    <span class="text-xs text-gray-500">{{ $task->board_column }}</span>
                </div>

                @if($task->description)
                    <p class="text-sm text-gray-600 mt-1">{{ $task->description }}</p>
                @endif

                <div class="mt-2 flex flex-wrap gap-2 text-sm">
                    {{-- Priority control --}}
                    <label class="flex items-center gap-1">
                        Priority:
                        <input type="number"
                               value="{{ $task->priority }}"
                               min="0" max="5"
                               wire:change="setPriority({{ $task->id }}, $event.target.value)"
                               class="border rounded w-14 px-1" />
                    </label>

                    {{-- Approval badge / button --}}
                    @if($task->client_approval === 'approved')
                        <span class="text-green-600 font-semibold">✓ Approved</span>
                    @elseif($task->board_column === 'Done')
                        <button wire:click="approveTask({{ $task->id }})"
                                class="bg-green-600 text-white px-3 py-0.5 rounded text-xs">
                            Approve
                        </button>
                    @endif
                </div>

                {{-- Comment form --}}
                <form wire:submit="addComment({{ $task->id }})" class="mt-3 flex gap-2">
                    <input wire:model="commentBody"
                           type="text"
                           placeholder="Add a comment…"
                           class="border rounded px-2 py-0.5 flex-1 text-sm" />
                    <button type="submit" class="text-sm bg-gray-200 px-3 py-0.5 rounded">Post</button>
                </form>
            </li>
        @endforeach
    </ul>
</div>
