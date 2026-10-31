<div>
    <x-ui.page-header title="Recurring charges" subtitle="Hosting, retainers and other repeating fees. Lines are generated daily as they fall due." />

    <form wire:submit="save" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="client_id" class="block text-sm font-medium text-slate-700">Client</label>
            <select wire:model.live="client_id" id="client_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                <option value="">Select...</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            @error('client_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="project_id" class="block text-sm font-medium text-slate-700">Project (optional)</label>
            <select wire:model="project_id" id="project_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                <option value="">Whole client</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            @error('project_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="description" class="block text-sm font-medium text-slate-700">Description</label>
            <input wire:model="description" id="description" type="text" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="amount" class="block text-sm font-medium text-slate-700">Amount</label>
            <input wire:model="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="frequency" class="block text-sm font-medium text-slate-700">Frequency</label>
            <select wire:model="frequency" id="frequency" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
            </select>
        </div>
        <div>
            <label for="next_due_on" class="block text-sm font-medium text-slate-700">First / next due</label>
            <input wire:model="next_due_on" id="next_due_on" type="date" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('next_due_on') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Add charge</button></div>
    </form>

    @if ($charges->isEmpty())
        <x-ui.empty-state title="No recurring charges" message="Add one above." />
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr><th class="px-5 py-3">Client</th><th class="px-5 py-3">Description</th><th class="px-5 py-3">Amount</th><th class="px-5 py-3">Next due</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($charges as $c)
                        <tr wire:key="rc-{{ $c->id }}" class="{{ $c->active ? '' : 'opacity-50' }}">
                            <td class="px-5 py-3">{{ $c->client->name }}@if ($c->project) <span class="text-slate-500">/ {{ $c->project->name }}</span>@endif</td>
                            <td class="px-5 py-3">{{ $c->description }} <span class="text-slate-500">({{ $c->frequency->value }})</span></td>
                            <td class="px-5 py-3">{{ \App\Support\Money::format($c->amount_cents, $c->client->currency) }}</td>
                            <td class="px-5 py-3">{{ $c->next_due_on->toFormattedDateString() }}</td>
                            <td class="px-5 py-3 text-right"><button wire:click="toggle({{ $c->id }})" class="btn btn-ghost">{{ $c->active ? 'Pause' : 'Resume' }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
