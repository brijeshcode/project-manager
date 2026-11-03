<div>
    <x-ui.breadcrumb :items="[['Projects', route('projects.index')], [$project->name, route('projects.show', ['project' => $project, 'tab' => 'modules'])], [$copyFrom ? 'Copy module' : 'Add module', null]]" />

    <x-ui.page-header :title="$copyFrom ? 'Copy module' : 'Add module'" :subtitle="$copyFrom ? 'Nothing is saved until you click Create module.' : $project->name" />

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <x-ui.card class="space-y-5">
            <div>
                <label class="label" for="name">Name</label>
                <input id="name" type="text" wire:model="name" class="input" placeholder="e.g. Checkout" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Description <span class="font-normal text-slate-400">(optional, Markdown)</span></label>
                <x-ui.markdown-editor model="description" :rows="8" :uploads="false" placeholder="What is this module about?" />
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </x-ui.card>

        @if ($copyFrom && $taskCount > 0)
            <label class="inline-flex cursor-pointer items-center gap-2">
                <input type="checkbox" wire:model="copyTasks" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" data-test="copy-tasks">
                <span class="text-sm font-medium text-slate-700">Also copy {{ $taskCount }} {{ Str::plural('task', $taskCount) }} and their subtasks</span>
            </label>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Create module</button>
            <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'modules']) }}" wire:navigate class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
