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

    {{-- Modules shared with the client --}}
    @foreach($modules as $module)
        @php($status = $module->status())
        <x-ui.card class="mb-4 space-y-3" data-test="portal-module">
            <div class="flex items-start justify-between gap-3">
                <h3 class="font-display text-sm font-semibold text-slate-900">{{ $module->name }}</h3>
                <x-ui.badge :color="\App\Models\Module::STATUS_COLORS[$status]">{{ \App\Models\Module::STATUS_LABELS[$status] }}</x-ui.badge>
            </div>
            @if($module->description)
                <x-ui.markdown :text="$module->description" />
            @endif
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-blue-500" style="width: {{ round($module->progress() * 100) }}%"></div>
            </div>
            @include('livewire.portal._threads', ['subject' => $module, 'kind' => 'module'])
            <form wire:submit="addModuleComment({{ $module->id }})" class="space-y-2">
                <x-ui.markdown-editor model="moduleBody.{{ $module->id }}" :rows="2" :uploads="false" placeholder="Comment on this module…" />
                <button type="submit" class="btn btn-secondary h-8 px-3 py-1 text-xs">Post</button>
            </form>
            @error('moduleBody.'.$module->id) <p class="field-error text-xs">{{ $message }}</p> @enderror
        </x-ui.card>
    @endforeach

    {{-- Task list --}}
    @forelse($tasks as $task)
        <x-ui.card class="mb-4 space-y-4" x-data="{ commenting: false }">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="font-display text-sm font-semibold text-slate-900">{{ $task->title }}</h3>
                    @if($task->description)
                        <x-ui.markdown :text="$task->description" class="mt-1" />
                    @endif
                    @if($task->deadline)
                        <p class="mt-1 text-xs text-slate-500">Due {{ $task->deadline->format('M j, Y') }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <x-ui.badge color="slate">{{ $task->column?->name }}</x-ui.badge>
                    @if($task->client_approval === 'approved')
                        <x-ui.badge color="green">Approved</x-ui.badge>
                    @elseif($task->column?->stage === 'done')
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

            @include('livewire.portal._threads', ['subject' => $task, 'kind' => 'task'])

            {{-- Comment toggle + form --}}
            <div>
                <button type="button" @click="commenting = !commenting"
                        class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    Add a comment
                </button>
                <form x-show="commenting" style="display:none"
                      wire:submit="addComment({{ $task->id }})"
                      @submit="commenting = false"
                      class="mt-2 space-y-2">
                    <x-ui.markdown-editor model="commentBody" :rows="2" :uploads="false" placeholder="Your comment…" />
                    <button type="submit" class="btn btn-secondary h-8 px-3 py-1 text-xs">Post</button>
                </form>
                @error('commentBody') <p class="field-error mt-1 text-xs">{{ $message }}</p> @enderror
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No tasks yet"
                          message="Once tasks are marked visible to clients they'll appear here. You can also request a new task above." />
    @endforelse

    <livewire:resources.panel :project="$project" />
</div>
