<div>
    <x-ui.page-header title="New invoice" :subtitle="$client ? $client->name : 'Choose a client'" />

    @if (! $client)
        <form wire:submit="choose" class="card flex items-end gap-3">
            <div>
                <label for="pick" class="label">Client</label>
                <select wire:model="pick" id="pick" class="select">
                    <option value="">Select...</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            @error('pick') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    @else
        <form wire:submit="save" class="space-y-6">
            <div class="card">
                <h2 class="mb-3 font-semibold">Pending work</h2>
                @forelse ($lines as $l)
                    <div wire:key="line-{{ $l->id }}" class="flex items-center gap-3 border-b border-slate-100 py-2 text-sm">
                        <label class="flex flex-1 items-center gap-3">
                            <input type="checkbox" wire:model="selected" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                            <span class="flex-1">{{ $l->description }}</span>
                            <span>{{ \App\Support\Money::format($l->amount_cents, $l->lineCurrency() ?? $client->currency) }}</span>
                        </label>
                        @unless ($l->source_type)
                            <button type="button" wire:click="discardLine({{ $l->id }})" wire:confirm="Discard this pending line permanently?" class="btn btn-ghost">Discard</button>
                        @endunless
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No pending lines. Add a custom line below.</p>
                @endforelse
                @error('lines') <p class="mt-2 field-error">{{ $message }}</p> @enderror
                @error('selected.*') <p class="mt-2 field-error">{{ $message }}</p> @enderror
            </div>

            <div class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold">Custom lines</h2>
                    <button type="button" wire:click="addCustom" class="btn btn-ghost">Add line</button>
                </div>
                @foreach ($custom as $i => $row)
                    <div wire:key="custom-{{ $i }}" class="mb-3 grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <input wire:model="custom.{{ $i }}.description" type="text" placeholder="Description" aria-label="Description" class="input" />
                            @error("custom.$i.description") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <input wire:model="custom.{{ $i }}.quantity" type="text" inputmode="decimal" placeholder="Qty" aria-label="Quantity" class="input" />
                            @error("custom.$i.quantity") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3">
                            <input wire:model="custom.{{ $i }}.unit_price" type="text" inputmode="decimal" placeholder="Unit price" aria-label="Unit price" class="input" />
                            @error("custom.$i.unit_price") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-1"><button type="button" wire:click="removeCustom({{ $i }})" class="btn btn-ghost" aria-label="Remove line">&times;</button></div>
                    </div>
                @endforeach
            </div>

            <div class="card grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="currency" class="label">Invoice currency</label>
                    <select wire:model.live="currency" id="currency" class="select">
                        @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                    </select>
                    @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="taxPercent" class="label">Tax %</label>
                    <input wire:model="taxPercent" id="taxPercent" type="text" inputmode="decimal" class="input" />
                    @error('taxPercent') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bankAccountId" class="label">Bank account</label>
                    <select wire:model="bankAccountId" id="bankAccountId" class="select">
                        <option value="">None</option>
                        @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                    </select>
                    @error('bankAccountId') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                @unless ($sameCurrency)
                    <div>
                        <label for="exchangeRate" class="label">Exchange rate to {{ $local }}</label>
                        <input wire:model="exchangeRate" id="exchangeRate" type="text" inputmode="decimal" placeholder="e.g. 83.5" class="input" />
                        @error('exchangeRate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                @endunless
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn btn-primary">Create &amp; send</button>
                <button type="button" wire:click="saveDraft" class="btn btn-secondary">Save as draft (not sent)</button>
            </div>
            <p class="text-xs text-slate-500">A draft does not change the client's balance. It shows on the client page as a future invoice until you send it.</p>
        </form>
    @endif
</div>
