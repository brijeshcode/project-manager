<div>
    @php $module = $task->module; $project = $module->project; @endphp
    <x-ui.breadcrumb :items="[['Projects', route('projects.index')], [$project->name, route('projects.show', ['project' => $project, 'tab' => 'modules'])], [$module->name, route('modules.show', [$project, $module])], [$task->title, route('tasks.show', $task)], [$subtask->title, null]]" />

    <div class="flex flex-col gap-3">
        @include('livewire.items._title', ['heading' => $subtask->title, 'canEdit' => $canEdit])
        <div class="flex flex-wrap items-center gap-2 sm:pl-7">
            <x-ui.badge :color="$subtask->is_done ? 'green' : 'slate'" data-test="done-badge">{{ $subtask->is_done ? 'Done' : 'Open' }}</x-ui.badge>
            <span class="text-sm text-slate-500">{{ $project->name }} &middot; {{ $task->module->name }} &middot; {{ $task->title }}</span>
            @if ($canEdit)
                <span class="ml-auto flex flex-wrap items-center gap-2">
                    <button wire:click="toggleDone" class="btn btn-secondary h-8 px-3 py-1 text-xs">{{ $subtask->is_done ? 'Reopen' : 'Mark done' }}</button>
                    <button wire:click="convertToTask" wire:confirm="Convert this subtask into a task of the module? Its comments and attachments move with it and this subtask is removed." class="btn btn-secondary h-8 px-3 py-1 text-xs" data-test="convert">Convert to task</button>
                    <button wire:click="deleteSubtask" wire:confirm="Delete this subtask?" class="btn btn-ghost h-8 px-3 py-1 text-xs">Delete</button>
                </span>
            @endif
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @include('livewire.items._description', ['text' => $subtask->description, 'canEdit' => $canEdit])

            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                @include('livewire.items._timeline', ['authorId' => null])
            </div>
        </div>

        <aside>
            <x-ui.card class="text-sm" padding="p-4">
                @include('livewire.items._sidebar-people', ['canEdit' => $canEdit])

                <x-ui.side-section title="Estimate" name="estimate" :panel="$panel" :can="$canEdit">
                    <p>{{ $subtask->estimate_hours }} h</p>
                    <x-slot:editor>
                        <input type="number" step="0.5" min="0" wire:model="estimate" class="input h-8 text-sm">
                        @error('estimate') <p class="field-error">{{ $message }}</p> @enderror
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Attachments" name="attachments">
                    @forelse ($attachments as $a)
                        <p><a href="{{ $a->url() }}" class="text-brand-600 hover:underline">{{ $a->name }}</a></p>
                    @empty
                        <p class="text-slate-400">None</p>
                    @endforelse
                </x-ui.side-section>

                @include('livewire.items._sidebar-participants')
            </x-ui.card>
        </aside>
    </div>
</div>
