<div>
    <x-ui.page-header :title="'Edit invoice '.$invoice->number" :subtitle="$client->name.' · '.$invoice->currency">
        <x-slot:actions>
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6">
        <div class="card">
            <h2 class="mb-3 font-semibold">Work on this invoice</h2>
            @forelse ($lines as $l)
                <div wire:key="line-{{ $l->id }}" class="border-b border-slate-100 py-2 text-sm dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" wire:model="selected" value="{{ $l->id }}" aria-label="Include line" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        @if (isset($edits[$l->id]))
                            <div class="grid flex-1 gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-7">
                                    <input wire:model="edits.{{ $l->id }}.description" type="text" aria-label="Description" class="input" />
                                    @error("edits.{$l->id}.description") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <input wire:model="edits.{{ $l->id }}.quantity" type="text" inputmode="decimal" aria-label="Quantity" class="input" />
                                    @error("edits.{$l->id}.quantity") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-3">
                                    <input wire:model="edits.{{ $l->id }}.unit_price" type="text" inputmode="decimal" aria-label="Unit price" class="input" />
                                    @error("edits.{$l->id}.unit_price") <p class="mt-1 field-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @else
                            <span class="flex-1">{{ $l->description }}</span>
                            <span>{{ \App\Support\Money::format($l->amount_cents, $invoice->currency) }}</span>
                        @endif
                        @if ($l->invoice_id === null)<x-ui.badge color="amber">pending</x-ui.badge>@endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No task lines. Use custom lines below.</p>
            @endforelse
            <p class="mt-2 text-xs text-slate-500">Unticked lines go back to pending. Ticking a pending line adds it to this invoice.</p>
            @error('lines') <p class="mt-2 field-error">{{ $message }}</p> @enderror
            @error('selected.*') <p class="mt-2 field-error">{{ $message }}</p> @enderror
        </div>

        <div class="card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">New lines</h2>
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

        <div class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="issuedOn" class="label">Issued on</label>
                <input wire:model="issuedOn" id="issuedOn" type="date" class="input" />
                @error('issuedOn') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="dueOn" class="label">Due on</label>
                <input wire:model="dueOn" id="dueOn" type="date" class="input" />
                @error('dueOn') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="terms" class="label">Terms</label>
                <input wire:model="terms" id="terms" type="text" placeholder="e.g. net-30" class="input" />
                @error('terms') <p class="mt-1 field-error">{{ $message }}</p> @enderror
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
                    <input wire:model="exchangeRate" id="exchangeRate" type="text" inputmode="decimal" class="input" />
                    @error('exchangeRate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
            @endunless
        </div>

        <button type="submit" class="btn btn-primary">Save changes</button>
    </form>
</div>
