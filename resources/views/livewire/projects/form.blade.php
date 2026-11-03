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
                <label class="label" for="currency">Currency</label>
                <select id="currency" wire:model="currency" class="select">
                    @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Tasks and module budgets use this currency. Defaults to the client's.</p>
                @error('currency') <p class="field-error">{{ $message }}</p> @enderror
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

            <div>
                <label class="label" for="aim">Aim of the project <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="aim" wire:model="aim" rows="3" placeholder="What problem are we solving?" class="textarea"></textarea>
                @error('aim') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label class="label" for="initial_budget">Initial budget ({{ $currency }})</label>
                    <input id="initial_budget" type="text" inputmode="decimal" wire:model.live.debounce.300ms="initial_budget" class="input">
                    @error('initial_budget') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="addons_amount">Addons amount ({{ $currency }})</label>
                    <input id="addons_amount" type="text" inputmode="decimal" wire:model.live.debounce.300ms="addons_amount" class="input">
                    @error('addons_amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="final_budget">Final budget ({{ $currency }})</label>
                    <input id="final_budget" type="text" value="{{ $finalBudget }}" readonly class="input bg-slate-50">
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label class="label" for="start_date">Start date</label>
                    <input id="start_date" type="date" wire:model="start_date" class="input">
                    @error('start_date') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="deadline">Deadline</label>
                    <input id="deadline" type="date" wire:model.live="deadline" class="input">
                    @error('deadline') <p class="field-error">{{ $message }}</p> @enderror
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

            @if ($this->changingDeadline())
                <div>
                    <label class="label" for="reason">Reason for deadline change</label>
                    <textarea id="reason" rows="2" wire:model="reason" class="textarea"></textarea>
                    @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
        </x-ui.card>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">{{ $projectId ? 'Save changes' : 'Create project' }}</button>
            <a href="{{ route('projects.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
