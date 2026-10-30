<div>
    <div class="mb-6">
        <a href="{{ route('projects.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">
            &larr; Projects
        </a>
    </div>

    <x-ui.page-header :title="$projectId ? 'Edit project' : 'New project'" subtitle="A project groups the modules, tasks, and time for one piece of client work." />

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <x-ui.card class="space-y-5">
            <div>
                <label class="label" for="client_id">Client</label>
                <select id="client_id" wire:model="client_id" class="select">
                    <option value="0">Select a client…</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('client_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="name">Project name</label>
                <input id="name" type="text" wire:model="name" placeholder="e.g. Northwind Storefront" class="input">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="description">Description <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="description" wire:model="description" rows="4" placeholder="What is this project about? Scope, goals, anything the team should know." class="textarea"></textarea>
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label class="label" for="start_date">Start date</label>
                    <input id="start_date" type="date" wire:model="start_date" class="input">
                    @error('start_date') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="buffer_percent">Buffer %</label>
                    <input id="buffer_percent" type="number" step="0.01" min="0" wire:model="buffer_percent" class="input">
                    @error('buffer_percent') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="status">Status</label>
                    <select id="status" wire:model="status" class="select">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="on_hold">On hold</option>
                        <option value="completed">Completed</option>
                    </select>
                    @error('status') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">{{ $projectId ? 'Save changes' : 'Create project' }}</button>
            <a href="{{ route('projects.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
