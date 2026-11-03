<div>
    @php
        $stage = $task->column?->stage;
        $overdue = $task->deadline && $task->deadline->isPast() && $stage !== 'done';
        $done = $subtasks->where('is_done', true)->count();
        $total = $subtasks->count();
        $initials = fn ($u) => collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    @endphp

    <x-ui.breadcrumb :items="[['Projects', route('projects.index')], [$project->name, route('projects.show', ['project' => $project, 'tab' => 'modules'])], [$module->name, route('modules.show', [$project, $module])], [$task->title, null]]" />

    <div class="flex flex-col gap-3">
        @include('livewire.items._title', ['heading' => $task->title, 'canEdit' => $canEdit])
        <div class="flex flex-wrap items-center gap-2 sm:pl-7">
            <x-ui.badge color="slate" data-test="column-badge">{{ $task->column?->name }}@if ($stage) <span class="text-slate-400">&middot; {{ str_replace('_', ' ', $stage) }}</span>@endif</x-ui.badge>
            @if ($task->status->value === 'draft') <x-ui.badge color="amber">Draft</x-ui.badge> @endif
            @if ($task->client_visible) <x-ui.badge color="brand">Visible to client</x-ui.badge> @endif
            <span class="text-sm text-slate-500">{{ $project->client->name }} &middot; {{ $project->name }} &middot; {{ $module->name }}</span>
            @if ($canCopy)
                <a href="{{ route('tasks.create', ['copy' => $task->id]) }}" wire:navigate class="btn btn-secondary ml-auto h-8 px-3 py-1 text-xs" data-test="copy">Copy</a>
            @endif
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @include('livewire.items._description', ['text' => $task->description, 'canEdit' => $canEdit])

            {{-- Subtask list, right below the description --}}
            <x-ui.card padding="p-0" data-test="subtask-list">
                <div class="border-b border-slate-100 px-5 py-3">
                    <div class="flex items-center justify-between">
                        <h2 class="font-display text-sm font-semibold text-slate-900">Subtasks <span class="font-normal text-slate-400" data-test="subtask-count">{{ $done }}/{{ $total }} done</span></h2>
                    </div>
                    @if ($total)
                        <div class="mt-2 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ round($done / $total * 100) }}%"></div></div>
                    @endif
                </div>
                <ul class="divide-y divide-slate-100" data-test="subtasks">
                    @forelse ($subtasks as $s)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <input type="checkbox" @checked($s->is_done) @disabled(! $canEdit) wire:click="toggleSubtask({{ $s->id }})" class="rounded border-slate-300 text-brand-600" aria-label="Done">
                            <a href="{{ route('subtasks.show', $s) }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm font-medium hover:text-brand-600', 'text-slate-800' => ! $s->is_done, 'text-slate-400 line-through' => $s->is_done])>{{ $s->title }}</a>
                            @if ($s->comments_count) <span class="text-xs text-slate-400">{{ $s->comments_count }} {{ Str::plural('comment', $s->comments_count) }}</span> @endif
                            <span class="flex -space-x-1">
                                @foreach ($s->assignees as $u)
                                    <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700 ring-2 ring-white">{{ $initials($u) }}</span>
                                @endforeach
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm text-slate-400">No subtasks yet.</li>
                    @endforelse
                </ul>
                @if ($canEdit)
                    <form wire:submit="addSubtask" class="flex gap-2 border-t border-slate-100 px-5 py-3">
                        <input type="text" wire:model="newSubtask" placeholder="Add a subtask…" class="input h-9 flex-1 text-sm">
                        <button type="submit" class="btn btn-secondary h-9 whitespace-nowrap text-sm">+ Subtask</button>
                    </form>
                    @error('newSubtask') <p class="field-error px-5 pb-3">{{ $message }}</p> @enderror
                @endif
            </x-ui.card>

            <div>
                <h2 class="mb-3 font-display text-lg font-semibold text-slate-900">Timeline</h2>
                @include('livewire.items._timeline', ['authorId' => $task->creator_id])
            </div>
        </div>

        {{-- Sidebar --}}
        <aside>
            <x-ui.card class="text-sm" padding="p-4">
                <x-ui.side-section title="Board column" name="column">
                    @if ($canEdit)
                        <select wire:change="moveColumn($event.target.value)" class="select h-9 text-sm" data-test="column-select">
                            @foreach ($columns as $col) <option value="{{ $col->id }}" @selected($col->id === $task->board_column_id)>{{ $col->name }} ({{ str_replace('_', ' ', $col->stage) }})</option> @endforeach
                        </select>
                    @else
                        <p>{{ $task->column?->name }}</p>
                    @endif
                </x-ui.side-section>

                @include('livewire.items._sidebar-people', ['canEdit' => $canEdit])

                <x-ui.side-section title="Deadline" name="deadline" :panel="$panel" :can="$canEdit">
                    @if ($task->deadline)
                        <span data-test="deadline" @class(['font-medium text-rose-600' => $overdue])>{{ $task->deadline->format('M j, Y') }}</span>
                    @else
                        <p class="text-slate-400">Not set</p>
                    @endif
                    <x-slot:editor>
                        <input type="date" wire:model="deadline" class="input h-8 text-sm">
                        @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
                        <input type="text" wire:model="deadlineReason" placeholder="Reason (optional)" class="input mt-1.5 h-8 text-sm">
                    </x-slot:editor>
                </x-ui.side-section>

                <x-ui.side-section title="Priority" name="priority" :panel="$panel" :can="$canEdit">
                    <p>{{ ['Low', 'Medium', 'High'][$task->priority] ?? $task->priority }}</p>
                    <x-slot:editor>
                        <select wire:model="priority" class="select h-8 text-sm"><option value="0">Low</option><option value="1">Medium</option><option value="2">High</option></select>
                    </x-slot:editor>
                </x-ui.side-section>

                @if ($canCost)
                    <x-ui.side-section title="Estimate" name="estimate" :panel="$panel" :can="$canEdit">
                        <p>{{ $task->estimate_hours }} h @if ($task->scope->value === 'out') &middot; out of scope{{ $task->price !== null ? ', price '.number_format($task->price, 2) : '' }} @endif</p>
                        <x-slot:editor>
                            <div class="space-y-1.5">
                                <select wire:model.live="scope" class="select h-8 text-sm"><option value="in">In scope</option><option value="out">Out of scope</option></select>
                                <input type="number" step="0.5" min="0" wire:model="estimate" placeholder="Hours" class="input h-8 text-sm">
                                @error('estimate') <p class="field-error">{{ $message }}</p> @enderror
                                <input type="number" step="0.01" min="0" wire:model="price" placeholder="Price (out of scope)" class="input h-8 text-sm" @disabled($scope !== 'out')>
                                @error('price') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </x-slot:editor>
                    </x-ui.side-section>
                @endif

                <x-ui.side-section title="Client visible" name="visibility" :panel="$panel" :can="$canEdit">
                    <p>{{ $task->client_visible ? 'Yes, shown in the client portal' : 'No' }}@if ($task->is_idea) &middot; idea / backlog @endif</p>
                    <x-slot:editor>
                        <div class="space-y-1.5">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="clientVisible" class="rounded border-slate-300 text-brand-600"> Visible to the client</label>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isIdea" class="rounded border-slate-300 text-brand-600"> Idea / backlog item</label>
                            <select wire:model="status" class="select h-8 text-sm"><option value="draft">Draft</option><option value="published">Published</option></select>
                        </div>
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

                @if ($canEdit)
                    <div class="pt-3"><a href="{{ route('tasks.edit', $task) }}" wire:navigate class="text-xs text-slate-500 hover:text-slate-700">Testing add-on and more &rarr;</a></div>
                @endif
            </x-ui.card>
        </aside>
    </div>
</div>
