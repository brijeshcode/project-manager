<div>
    <x-ui.page-header title="New invoice" :subtitle="$client ? $client->name : 'Choose a client'" />

    @if (! $client)
        <form wire:submit="choose" class="card flex items-end gap-3">
            <div>
                <label for="pick" class="block text-sm font-medium text-slate-700">Client</label>
                <select wire:model="pick" id="pick" class="mt-1 block rounded-md border-slate-300 text-sm">
                    <option value="">Select...</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            @error('pick') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    @else
        <form wire:submit="save" class="space-y-6">
            <div class="card">
                <h2 class="mb-3 font-semibold">Pending work</h2>
                @forelse ($lines as $l)
                    <label wire:key="line-{{ $l->id }}" class="flex items-center gap-3 border-b border-slate-100 py-2 text-sm">
                        <input type="checkbox" wire:model="selected" value="{{ $l->id }}" class="rounded border-slate-300" />
                        <span class="flex-1">{{ $l->description }}</span>
                        <span>{{ \App\Support\Money::format($l->amount_cents, $client->currency) }}</span>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">No pending lines. Add a custom line below.</p>
                @endforelse
                @error('lines') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('selected.*') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold">Custom lines</h2>
                    <button type="button" wire:click="addCustom" class="btn btn-ghost">Add line</button>
                </div>
                @foreach ($custom as $i => $row)
                    <div wire:key="custom-{{ $i }}" class="mb-3 grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <input wire:model="custom.{{ $i }}.description" type="text" placeholder="Description" aria-label="Description" class="block w-full rounded-md border-slate-300 text-sm" />
                            @error("custom.$i.description") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <input wire:model="custom.{{ $i }}.quantity" type="text" inputmode="decimal" placeholder="Qty" aria-label="Quantity" class="block w-full rounded-md border-slate-300 text-sm" />
                            @error("custom.$i.quantity") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3">
                            <input wire:model="custom.{{ $i }}.unit_price" type="text" inputmode="decimal" placeholder="Unit price" aria-label="Unit price" class="block w-full rounded-md border-slate-300 text-sm" />
                            @error("custom.$i.unit_price") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-1"><button type="button" wire:click="removeCustom({{ $i }})" class="btn btn-ghost" aria-label="Remove line">&times;</button></div>
                    </div>
                @endforeach
            </div>

            <div class="card grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="taxPercent" class="block text-sm font-medium text-slate-700">Tax %</label>
                    <input wire:model="taxPercent" id="taxPercent" type="text" inputmode="decimal" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                    @error('taxPercent') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bankAccountId" class="block text-sm font-medium text-slate-700">Bank account</label>
                    <select wire:model="bankAccountId" id="bankAccountId" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                        <option value="">None</option>
                        @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                    </select>
                    @error('bankAccountId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="inrRate" class="block text-sm font-medium text-slate-700">INR rate (optional)</label>
                    <input wire:model="inrRate" id="inrRate" type="text" inputmode="decimal" placeholder="e.g. 83.5" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                    @error('inrRate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Create invoice</button>
        </form>
    @endif
</div>
