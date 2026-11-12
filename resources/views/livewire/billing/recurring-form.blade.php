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
                    <select wire:model.live="frequency" id="frequency" class="select">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                    @error('frequency') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="next_due_on" class="label">{{ $chargeId ? 'Next due' : 'First due' }}</label>
                    <input wire:model.live="next_due_on" id="next_due_on" type="date" class="input dark:[color-scheme:dark]" />
                    @error('next_due_on') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-gray-300" data-test="pass-through">
                <input type="checkbox" wire:model="pass_through" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                <span><span class="font-medium">Pass-through cost (not my income)</span><span class="block text-xs text-slate-500 dark:text-gray-400">E.g. a server or hosting you pay for. The client is billed as usual, but it's left out of your income figures.</span></span>
            </label>
            @if ($chargeId)
                <p class="text-xs text-slate-500 dark:text-gray-400">Changes apply to future periods. Lines already added keep their amount.</p>
            @else
                <fieldset class="space-y-2" data-test="approval-choice">
                    <legend class="label">Approval</legend>
                    @foreach ([1 => ['Ask the client to approve in the portal', 'Nothing is billed until they approve. You can also start it yourself from the service page.'], 0 => ['Already agreed — start now (auto-approved)', 'It starts right away and is marked as approved by your team.']] as $val => [$title, $hint])
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm', 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' => (int) $needsApproval === $val, 'border-slate-200 dark:border-gray-700' => (int) $needsApproval !== $val])>
                            <input type="radio" wire:model.live="needsApproval" value="{{ $val }}" class="mt-0.5 border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" data-test="needs-approval-{{ $val }}">
                            <span><span class="font-medium text-slate-900 dark:text-white">{{ $title }}</span><span class="block text-xs text-slate-500 dark:text-gray-400">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
                @if (! $needsApproval && $pastCount > 0)
                    <label class="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/10 dark:text-amber-200" data-test="add-past">
                        <input type="checkbox" wire:model.live="addPast" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                        <span><span class="font-medium">Add past periods</span>
                            <span class="block text-xs">{{ $addPast ? 'Adds '.$pastCount.' past '.Str::plural('period', $pastCount).' from '.\Carbon\Carbon::parse($next_due_on)->toFormattedDateString().' as pending lines, ready for the next invoice.' : 'Past periods are skipped; billing starts from the next due date on or after today.' }}</span>
                        </span>
                    </label>
                @endif
            @endif
        </x-ui.card>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $chargeId ? 'Save changes' : 'Add service' }}</button>
            <a href="{{ $chargeId ? route('billing.recurring.show', $chargeId) : route('billing.recurring') }}" wire:navigate class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
