<div>
    <div class="mb-6">
        <a href="{{ $taskId ? url()->previous() : route('projects.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Back
        </a>
    </div>

    <x-ui.page-header :title="$taskId ? 'Edit task' : ($copyFrom ? 'Copy task' : 'New task')" :subtitle="$copyFrom ? 'Nothing is saved until you click Create task.' : 'Fill in the details for this task.'" />

    <form wire:submit="save" class="max-w-3xl space-y-6">

        {{-- Core --}}
        <x-ui.card class="space-y-5">
            @unless ($taskId)
                <div>
                    <label class="label" for="module_id">Module</label>
                    <select id="module_id" wire:model.live="module_id" class="select">
                        @foreach ($moduleOptions as $ms)
                            <optgroup label="{{ $ms->first()->project->name }}">
                                @foreach ($ms as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('module_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endunless
            <div>
                <label class="label" for="title">Title</label>
                <input id="title" type="text" wire:model="title" placeholder="What needs doing?" class="input">
                @error('title') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="description">Description <span class="font-normal text-slate-400">(optional)</span></label>
                <x-ui.markdown-editor model="description" :rows="6" :uploads="(bool) $taskId" placeholder="Context, acceptance criteria, links…" />
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label class="label" for="status">Status</label>
                    <select id="status" wire:model="status" class="select">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                    </select>
                    @error('status') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="priority">Priority</label>
                    <select id="priority" wire:model="priority" class="select">
                        <option value="0">Low</option>
                        <option value="1">Medium</option>
                        <option value="2">High</option>
                    </select>
                    @error('priority') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="board_column_id">Board column</label>
                    <select id="board_column_id" wire:model="board_column_id" class="select">
                        <option value="">First column</option>
                        @foreach($boardColumns as $col)
                            <option value="{{ $col->id }}">{{ $col->name }}</option>
                        @endforeach
                    </select>
                    @error('board_column_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="max-w-xs">
                <label class="label" for="deadline">Deadline <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="deadline" type="date" wire:model="deadline" class="input">
                @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-6">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="is_idea" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-sm font-medium text-slate-700">Idea / backlog item</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="client_visible" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-sm font-medium text-slate-700">Visible to client</span>
                </label>
            </div>
        </x-ui.card>

        {{-- Scope & pricing --}}
        @if($task ? auth()->user()->can('updateCostTime', $task) : auth()->user()->hasAnyRole(['super_admin', 'project_manager']))
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-4">Scope &amp; pricing</p>
            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label class="label" for="scope">Scope</label>
                    <select id="scope" wire:model="scope" class="select">
                        <option value="in">In scope</option>
                        <option value="out">Out of scope</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="estimate_hours">Estimate (hours)</label>
                    <input id="estimate_hours" type="number" step="0.5" min="0" wire:model="estimate_hours" class="input">
                    @error('estimate_hours') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="price">Price <span class="font-normal text-slate-400">(out of scope)</span></label>
                    <input id="price" type="number" step="0.01" min="0" wire:model="price" class="input" {{ $scope !== 'out' ? 'disabled' : '' }}>
                    @error('price') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>
        @endif

        {{-- Testing add-on --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Testing add-on</p>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="testing_enabled" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-sm font-medium text-slate-700">Enable</span>
                </label>
            </div>
            @if($testing_enabled)
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="label" for="testing_add_type">Type</label>
                    <select id="testing_add_type" wire:model="testing_add_type" class="select">
                        <option value="">Select…</option>
                        <option value="percent">Percentage</option>
                        <option value="fixed">Fixed hours</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="testing_add_value">Value</label>
                    <input id="testing_add_value" type="number" step="0.1" min="0" wire:model="testing_add_value" class="input">
                </div>
            </div>
            @endif
        </x-ui.card>

        {{-- Labels --}}
        @if($allLabels->count())
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">Labels</p>
            <div class="flex flex-wrap gap-2">
                @foreach($allLabels as $label)
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="labelIds" value="{{ $label->id }}"
                               class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm font-medium text-slate-700">{{ $label->name }}</span>
                        @if($label->color)
                            <span class="inline-block w-3 h-3 rounded-full" style="background:{{ $label->color }}"></span>
                        @endif
                    </label>
                @endforeach
            </div>
        </x-ui.card>
        @endif

        {{-- Assignees --}}
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">Assignees</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach($allUsers as $user)
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="assigneeIds" value="{{ $user->id }}"
                               class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm text-slate-700">{{ $user->name }}</span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        @if ($copyFrom && $copyCount > 0)
            <label class="inline-flex cursor-pointer items-center gap-2">
                <input type="checkbox" wire:model="copySubtasks" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" data-test="copy-subtasks">
                <span class="text-sm font-medium text-slate-700">Also copy {{ $copyCount }} {{ Str::plural('subtask', $copyCount) }}</span>
            </label>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">{{ $taskId ? 'Save changes' : 'Create task' }}</button>
            <a href="{{ url()->previous() }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
