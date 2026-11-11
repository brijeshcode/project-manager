<div>
    <x-ui.page-header :title="'Edit invoice '.$invoice->number" :subtitle="$client->name.' · '.$invoice->currency">
        <x-slot:actions>
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    @php $fmt = fn ($c) => \App\Support\Money::format($c, $summary['currency']); @endphp
    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Lines on this invoice</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Unticked lines go back to pending for a later invoice. Ticking a pending line adds it to this one.</p>
                    </div>
                    @if ($lines->isNotEmpty())
                        @if (count($selected) === $lines->count())
                            <button type="button" wire:click="$set('selected', [])" class="btn btn-ghost shrink-0">Clear all</button>
                        @else
                            <button type="button" wire:click="$set('selected', @js($lines->pluck('id')->map(fn ($i) => (string) $i)->all()))" class="btn btn-ghost shrink-0">Select all</button>
                        @endif
                    @endif
                </div>

                @if ($lines->isNotEmpty())
                    @if ($edits)
                        <div class="mt-4 hidden grid-cols-12 gap-3 pl-7 text-xs font-medium uppercase tracking-wide text-slate-500 sm:grid dark:text-gray-400">
                            <span class="col-span-6">Description</span><span class="col-span-2">Qty</span><span class="col-span-2">Unit price</span><span class="col-span-2 text-right">Amount</span>
                        </div>
                    @endif
                    <div class="mt-4 divide-y divide-slate-100 border-y border-slate-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($lines as $l)
                            @php $src = match (true) { $l->source_type === \App\Models\Task::class => 'Task', $l->source_type === \App\Models\Sprint::class => 'Sprint', $l->recurring_charge_id !== null => 'Recurring', default => 'Manual' }; @endphp
                            <div wire:key="line-{{ $l->id }}" class="flex items-start gap-3 py-3 text-sm">
                                <input type="checkbox" wire:model.live="selected" value="{{ $l->id }}" aria-label="Include line" class="mt-1 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                                @if (isset($edits[$l->id]))
                                    <div class="grid flex-1 items-start gap-3 sm:grid-cols-12">
                                        <div class="sm:col-span-6">
                                            <input wire:model.live.debounce.500ms="edits.{{ $l->id }}.description" type="text" placeholder="Description" aria-label="Description" class="input" />
                                            @error("edits.{$l->id}.description") <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="sm:col-span-2">
                                            <input wire:model.live.debounce.500ms="edits.{{ $l->id }}.quantity" type="text" inputmode="decimal" placeholder="1" aria-label="Quantity" class="input" />
                                            @error("edits.{$l->id}.quantity") <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="sm:col-span-2">
                                            <input wire:model.live.debounce.500ms="edits.{{ $l->id }}.unit_price" type="text" inputmode="decimal" placeholder="0.00" aria-label="Unit price" class="input" />
                                            @error("edits.{$l->id}.unit_price") <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="flex items-center justify-end sm:col-span-2 sm:h-10">
                                            <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ \App\Support\Money::format($summary['edits'][$l->id] ?? 0, $l->lineCurrency() ?? $client->currency) }}</span>
                                        </div>
                                    </div>
                                @else
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-slate-800 dark:text-gray-100">{{ $l->description }}</span>
                                        <span class="text-xs text-slate-500 dark:text-gray-400">{{ $src }}</span>
                                    </span>
                                    <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ \App\Support\Money::format($l->amount_cents, $l->lineCurrency() ?? $client->currency) }}</span>
                                @endif
                                @if ($l->invoice_id === null)<x-ui.badge color="amber">pending</x-ui.badge>@endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 dark:border-gray-700 dark:text-gray-400">
                        No lines to pick from. Add a line below.
                    </div>
                @endif
                @error('lines') <p class="field-error">{{ $message }}</p> @enderror
                @error('selected.*') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Additional lines</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Anything not tracked as work, such as hosting, licences or expenses. Prices are in {{ $summary['currency'] }}.</p>
                    </div>
                    <button type="button" wire:click="addCustom" class="btn btn-secondary shrink-0">Add line</button>
                </div>

                @if ($custom)
                    <div class="mt-4 hidden grid-cols-12 gap-3 text-xs font-medium uppercase tracking-wide text-slate-500 sm:grid dark:text-gray-400">
                        <span class="col-span-6">Description</span><span class="col-span-2">Qty</span><span class="col-span-2">Unit price</span><span class="col-span-2 text-right">Amount</span>
                    </div>
                    <div class="mt-2 space-y-3">
                        @foreach ($custom as $i => $row)
                            <div wire:key="custom-{{ $i }}" class="grid items-start gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-6">
                                    <input wire:model.live.debounce.500ms="custom.{{ $i }}.description" type="text" placeholder="Description" aria-label="Description" class="input" />
                                    @error("custom.$i.description") <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <input wire:model.live.debounce.500ms="custom.{{ $i }}.quantity" type="text" inputmode="decimal" placeholder="1" aria-label="Quantity" class="input" />
                                    @error("custom.$i.quantity") <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <input wire:model.live.debounce.500ms="custom.{{ $i }}.unit_price" type="text" inputmode="decimal" placeholder="0.00" aria-label="Unit price" class="input" />
                                    @error("custom.$i.unit_price") <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-center justify-end gap-1 sm:col-span-2">
                                    <span class="tabular-nums text-sm text-slate-800 dark:text-gray-100">{{ $fmt($summary['custom'][$i] ?? 0) }}</span>
                                    <button type="button" wire:click="removeCustom({{ $i }})" class="btn btn-ghost px-2 text-slate-400 hover:text-rose-600" title="Remove line" aria-label="Remove line">&times;</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="lg:col-span-1">
            <div class="card space-y-5 p-6 lg:sticky lg:top-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Invoice details</h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <div>
                        <label for="issuedOn" class="label">Issued on</label>
                        <input wire:model="issuedOn" id="issuedOn" type="date" class="input" />
                        @error('issuedOn') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="dueOn" class="label">Due on</label>
                        <input wire:model="dueOn" id="dueOn" type="date" class="input" />
                        @error('dueOn') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="terms" class="label">Terms</label>
                        <input wire:model="terms" id="terms" type="text" placeholder="e.g. net-30" class="input" />
                        @error('terms') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="taxPercent" class="label">Tax %</label>
                        <input wire:model.live.debounce.500ms="taxPercent" id="taxPercent" type="text" inputmode="decimal" class="input" />
                        @error('taxPercent') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="bankAccountId" class="label">Pay to</label>
                        <select wire:model="bankAccountId" id="bankAccountId" class="select">
                            <option value="">No bank details</option>
                            @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-gray-400">Printed on the invoice so the client knows where to pay.</p>
                        @error('bankAccountId') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @unless ($sameCurrency)
                        <div>
                            <label for="exchangeRate" class="label">Exchange rate to {{ $local }}</label>
                            <input wire:model.live.debounce.500ms="exchangeRate" id="exchangeRate" type="text" inputmode="decimal" placeholder="e.g. 83.5" class="input" />
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-gray-400">1 {{ $summary['currency'] }} in {{ $local }}. Locked on this invoice for your reports. Not shown to the client.</p>
                            @error('exchangeRate') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endunless
                </div>

                <dl class="space-y-2 border-t border-slate-100 pt-5 text-sm dark:border-gray-700">
                    <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Subtotal ({{ $summary['count'] }} {{ \Illuminate\Support\Str::plural('line', $summary['count']) }})</dt><dd class="tabular-nums">{{ $fmt($summary['subtotal']) }}</dd></div>
                    <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Tax</dt><dd class="tabular-nums">{{ $fmt($summary['tax']) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold text-gray-900 dark:border-gray-700 dark:text-white"><dt>Total</dt><dd class="tabular-nums" data-test="invoice-total">{{ $fmt($summary['subtotal'] + $summary['tax']) }}</dd></div>
                </dl>

                <button type="submit" class="btn btn-primary w-full">Save changes</button>
                <p class="text-xs text-slate-500 dark:text-gray-400">Saving a sent invoice updates the client's balance. A draft stays a draft until you send it.</p>
            </div>
        </div>
    </form>
</div>
