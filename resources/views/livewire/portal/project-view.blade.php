<div>
    <div class="mb-6">
        <a href="{{ route('portal.dashboard') }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Your projects
        </a>
    </div>

    <x-ui.page-header :title="$project->name"
                      subtitle="Your tasks on this project. Request work, add comments, or approve completed items." />

    {{-- Request a new task --}}
    <div class="mb-6">
        <form wire:submit="createTask" class="flex gap-2">
            <input wire:model="newTitle" type="text"
                   placeholder="Request a new task…"
                   class="input flex-1">
            <button type="submit" class="btn btn-primary whitespace-nowrap">+ Request</button>
        </form>
        @error('newTitle') <p class="field-error mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Task list --}}
    @forelse($tasks as $task)
        <x-ui.card class="mb-4 space-y-4" x-data="{ commenting: false }">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="font-display text-sm font-semibold text-slate-900">{{ $task->title }}</h3>
                    @if($task->description)
                        <p class="mt-1 text-sm text-slate-500">{{ $task->description }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <x-ui.badge color="slate">{{ $task->board_column }}</x-ui.badge>
                    @if($task->client_approval === 'approved')
                        <x-ui.badge color="green">Approved</x-ui.badge>
                    @elseif($task->board_column === 'Done')
                        <button wire:click="approveTask({{ $task->id }})"
                                class="btn btn-primary h-7 px-3 py-1 text-xs">
                            Approve
                        </button>
                    @endif
                </div>
            </div>

            {{-- Priority --}}
            <div class="flex items-center gap-3">
                <label class="text-xs font-medium text-slate-500">Priority</label>
                <div class="flex items-center gap-1">
                    @foreach([0 => 'Low', 1 => 'Med', 2 => 'High'] as $val => $label)
                        <button type="button"
                                wire:click="setPriority({{ $task->id }}, {{ $val }})"
                                @class(['rounded px-2 py-0.5 text-xs font-medium',
                                    'bg-brand-600 text-white' => $task->priority === $val,
                                    'bg-slate-100 text-slate-600 hover:bg-slate-200' => $task->priority !== $val])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Comment toggle + form --}}
            <div>
                <button type="button" @click="commenting = !commenting"
                        class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    Add a comment
                </button>
                <form x-show="commenting" style="display:none"
                      wire:submit="addComment({{ $task->id }})"
                      @submit="commenting = false"
                      class="mt-2 flex gap-2">
                    <input wire:model="commentBody" type="text"
                           placeholder="Your comment…" class="input flex-1 text-sm h-8 py-1">
                    <button type="submit" class="btn btn-secondary h-8 px-3 py-1 text-xs">Post</button>
                </form>
                @error('commentBody') <p class="field-error mt-1 text-xs">{{ $message }}</p> @enderror
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No tasks yet"
                          message="Once tasks are marked visible to clients they'll appear here. You can also request a new task above." />
    @endforelse
</div>
