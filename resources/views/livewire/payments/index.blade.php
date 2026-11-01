<div>
    <x-ui.page-header title="Payments" subtitle="Record money received from clients. Payments cannot be edited once recorded." />

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
                <option value="">None</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            @error('project_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="invoice_id" class="block text-sm font-medium text-slate-700">Invoice (optional)</label>
            <select wire:model="invoice_id" id="invoice_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                <option value="">None</option>
                @foreach ($invoices as $i)<option value="{{ $i->id }}">{{ $i->number }}</option>@endforeach
            </select>
            @error('invoice_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="amount" class="block text-sm font-medium text-slate-700">Amount</label>
            <input wire:model="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="paid_on" class="block text-sm font-medium text-slate-700">Paid on</label>
            <input wire:model="paid_on" id="paid_on" type="date" max="{{ today()->toDateString() }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('paid_on') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="type" class="block text-sm font-medium text-slate-700">Type</label>
            <select wire:model="type" id="type" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                @foreach ($types as $t)<option value="{{ $t->value }}">{{ ucfirst(str_replace('_', ' ', $t->value)) }}</option>@endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="method" class="block text-sm font-medium text-slate-700">Method (optional)</label>
            <input wire:model="method" id="method" type="text" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('method') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="lg:col-span-2">
            <label for="notes" class="block text-sm font-medium text-slate-700">Notes (optional)</label>
            <input wire:model="notes" id="notes" type="text" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Record payment</button></div>
    </form>

    <div class="mb-4 flex items-center gap-4">
        <label for="clientFilter" class="sr-only">Client</label>
        <select wire:model.live="clientFilter" id="clientFilter" class="rounded-md border-slate-300 text-sm">
            <option value="">All clients</option>
            @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        @if ($balanceClient)
            <p class="text-sm">{{ $balanceClient->name }} balance:
                <strong class="{{ $balance < 0 ? 'text-red-600' : 'text-green-700' }}">{{ \App\Support\Money::format($balance, $balanceClient->currency) }}</strong>
                <span class="text-slate-500">({{ $balance < 0 ? 'owes' : 'credit' }})</span></p>
        @endif
    </div>

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments" message="Record one above." />
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr><th class="px-5 py-3">Date</th><th class="px-5 py-3">Client</th><th class="px-5 py-3">Amount</th><th class="px-5 py-3">Type</th><th class="px-5 py-3">Applied to</th><th class="px-5 py-3">Notes</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($payments as $p)
                        <tr wire:key="pay-{{ $p->id }}">
                            <td class="px-5 py-3">{{ $p->paid_on->toFormattedDateString() }}</td>
                            <td class="px-5 py-3">{{ $p->client->name }}</td>
                            <td class="px-5 py-3">{{ \App\Support\Money::format($p->amount_cents, $p->client->currency) }}</td>
                            <td class="px-5 py-3">{{ ucfirst(str_replace('_', ' ', $p->type->value)) }}@if ($p->method) <span class="text-slate-500">({{ $p->method }})</span>@endif</td>
                            <td class="px-5 py-3">{{ $p->invoice?->number }}@if ($p->project) <span class="text-slate-500">{{ $p->project->name }}</span>@endif</td>
                            <td class="px-5 py-3">{{ $p->notes }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</div>
