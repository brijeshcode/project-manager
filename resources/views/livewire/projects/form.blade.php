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

            @unless ($projectId)
                <div>
                    <label class="label" for="template">Board template</label>
                    <select id="template" wire:model="template" class="select">
                        @foreach ($templates as $tpl)<option value="{{ $tpl }}">{{ ucfirst($tpl) }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">The starting board columns. You can edit them later on the Board tab.</p>
                    @error('template') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endunless

            <div>
                <label class="label" for="name">Project name</label>
                <input id="name" type="text" wire:model="name" placeholder="e.g. Northwind Storefront" class="input">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="key">Project key <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="key" type="text" wire:model.blur="key" maxlength="5" placeholder="Auto, e.g. NWS" class="input w-40 uppercase" data-test="project-key">
                <p class="mt-1 text-xs text-slate-500">2-5 letters or digits. Tasks are numbered with it, like KEY-12. Leave blank to derive it from the name.</p>
                @error('key') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="description">Description <span class="font-normal text-slate-400">(optional)</span></label>
                <x-ui.markdown-editor model="description" :rows="5" :uploads="false" placeholder="What is this project about? Scope, goals, anything the team should know." />
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="aim">Aim of the project <span class="font-normal text-slate-400">(optional)</span></label>
                <x-ui.markdown-editor model="aim" :rows="3" :uploads="false" placeholder="What problem are we solving?" />
                @error('aim') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3" data-test="calculated-budget">
                @foreach ([['initial_budget', 'Initial budget'], ['addons_amount', 'Add-ons'], ['final_budget', 'Final budget']] as [$f, $l])
                    <div>
                        <label class="label" for="{{ $f }}">{{ $l }} ({{ $currency }})</label>
                        <input id="{{ $f }}" type="text" value="{{ $project ? \App\Support\Money::format(\App\Support\Money::toCents($project->$f), $project->currency) : '0.00' }}" readonly class="input bg-slate-50 dark:bg-gray-800" data-test="{{ $f }}">
                    </div>
                @endforeach
                <p class="text-xs text-slate-500 sm:col-span-3">Calculated from the project's modules: the initial budget is what the modules were budgeted at, add-ons are accepted add-on tasks, and the final budget is their sum. Set budgets on each module.</p>
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
