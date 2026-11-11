<div class="mb-6 space-y-3" data-test="request-forms">
    @if ($moduleFormId === null && $taskFormId === null)
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="openTaskRequest" class="btn btn-primary gap-1" data-test="request-task"><x-icon name="plus" /> Request a task</button>
            <button type="button" wire:click="openModuleRequest" class="btn btn-secondary gap-1" data-test="request-module"><x-icon name="plus" /> Request a module</button>
        </div>
    @endif

    @if ($moduleFormId !== null)
        <x-ui.card>
            <form wire:submit="saveModuleRequest" class="space-y-3" data-test="module-request-form">
                <h3 class="font-display text-sm font-semibold text-slate-900">{{ $moduleFormId ? 'Edit module request' : 'Request a module' }}</h3>
                <div>
                    <label class="label" for="rq-m-name">Name</label>
                    <input id="rq-m-name" type="text" wire:model="moduleForm.name" class="input">
                    @error('moduleForm.name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Description</label>
                    <x-ui.markdown-editor model="moduleForm.description" :rows="4" :uploads="false" placeholder="What should this module cover?" />
                    @error('moduleForm.description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="rq-m-deadline">Deadline</label>
                        <input id="rq-m-deadline" type="date" wire:model="moduleForm.deadline" class="input">
                        @error('moduleForm.deadline') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="rq-m-priority">Priority</label>
                        <select id="rq-m-priority" wire:model="moduleForm.priority" class="select">
                            @foreach (\App\Support\Look::PRIORITIES as $pv => [$pl]) <option value="{{ $pv }}">{{ $pl }}</option> @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ $moduleFormId ? 'Save and resubmit' : 'Send request' }}</button>
                    <button type="button" wire:click="closeRequestForms" class="btn btn-ghost">Cancel</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($taskFormId !== null)
        <x-ui.card>
            <form wire:submit="saveTaskRequest" class="space-y-3" data-test="task-request-form">
                <h3 class="font-display text-sm font-semibold text-slate-900">{{ $taskFormId ? 'Edit task request' : 'Request a task' }}</h3>
                @if ($requestModules->isEmpty())
                    <p class="text-sm text-slate-500">There is no approved module to add a task to yet.</p>
                @endif
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="rq-t-module">Module</label>
                        <select id="rq-t-module" wire:model="taskForm.module_id" class="select">
                            @foreach ($requestModules as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                        </select>
                        @error('taskForm.module_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="rq-t-title">Title</label>
                        <input id="rq-t-title" type="text" wire:model="taskForm.title" class="input">
                        @error('taskForm.title') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label">Description</label>
                    <x-ui.markdown-editor model="taskForm.description" :rows="4" :uploads="false" placeholder="Describe what you need…" />
                    @error('taskForm.description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="label" for="rq-t-type">Type</label>
                        <select id="rq-t-type" wire:model="taskForm.task_type_id" class="select">
                            @foreach ($requestTypes as $o) <option value="{{ $o->id }}">{{ $o->name }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="rq-t-priority">Priority</label>
                        <select id="rq-t-priority" wire:model="taskForm.priority" class="select">
                            @foreach (\App\Support\Look::PRIORITIES as $pv => [$pl]) <option value="{{ $pv }}">{{ $pl }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="rq-t-deadline">Deadline</label>
                        <input id="rq-t-deadline" type="date" wire:model="taskForm.deadline" class="input">
                        @error('taskForm.deadline') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($requestLabels->isNotEmpty())
                    <div class="flex flex-wrap gap-3">
                        @foreach ($requestLabels as $label)
                            <label class="inline-flex cursor-pointer items-center gap-1.5">
                                <input type="checkbox" wire:model="taskForm.labels" value="{{ $label->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                                <x-ui.label-chip :label="$label" class="text-sm" />
                            </label>
                        @endforeach
                    </div>
                @endif
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary" @disabled($requestModules->isEmpty())>{{ $taskFormId ? 'Save and resubmit' : 'Send request' }}</button>
                    <button type="button" wire:click="closeRequestForms" class="btn btn-ghost">Cancel</button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
