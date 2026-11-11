<div>
    <x-ui.breadcrumb :items="array_filter([
        ['Recurring services', route('billing.recurring')],
        $chargeId ? [$description ?: 'Service', route('billing.recurring.show', $chargeId)] : null,
        [$chargeId ? 'Edit' : 'New service', null],
    ])" />

    <x-ui.page-header :title="$chargeId ? 'Edit service' : 'New recurring service'" subtitle="A fee billed on a schedule. Each due date adds a billable line you can put on the client's next invoice." />

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <x-ui.card class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="client_id" class="label">Client</label>
                    <select wire:model.live="client_id" id="client_id" class="select" @disabled($clientLocked)>
                        <option value="">Select a client…</option>
                        @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @if ($clientLocked)<p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Fixed: this service has already added billable lines for this client.</p>@endif
                    @error('client_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="project_id" class="label">Project <span class="font-normal text-slate-400">(optional)</span></label>
                    <select wire:model="project_id" id="project_id" class="select" @disabled(! $client_id)>
                        <option value="">None — client-wide</option>
                        @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Priced in the project's currency, else the client's.</p>
                    @error('project_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="description" class="label">Service</label>
                <input wire:model="description" id="description" type="text" maxlength="255" placeholder="e.g. Website hosting" class="input" />
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label for="amount" class="label">Amount</label>
                    <input wire:model="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input tabular-nums" />
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="frequency" class="label">Billed</label>
                    <select wire:model="frequency" id="frequency" class="select">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                    @error('frequency') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="next_due_on" class="label">{{ $chargeId ? 'Next due' : 'First due' }}</label>
                    <input wire:model="next_due_on" id="next_due_on" type="date" class="input dark:[color-scheme:dark]" />
                    @error('next_due_on') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            @if ($chargeId)
                <p class="text-xs text-slate-500 dark:text-gray-400">Changes apply to future periods. Lines already added keep their amount.</p>
            @endif
        </x-ui.card>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $chargeId ? 'Save changes' : 'Add service' }}</button>
            <a href="{{ $chargeId ? route('billing.recurring.show', $chargeId) : route('billing.recurring') }}" wire:navigate class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
