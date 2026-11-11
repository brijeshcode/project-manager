<div>
    <x-ui.page-header title="New invoice" :subtitle="$client ? 'Billing '.$client->name : 'Step 1 of 2 · Choose who you are billing'">
        <x-slot:actions>
            @if ($client)<a href="{{ route('invoices.create') }}" class="btn btn-ghost">Change client</a>@endif
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $client)
        <form wire:submit="choose" class="card max-w-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Who is this invoice for?</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Completed billable tasks, sprints and recurring charges for the client are gathered for you on the next step. You can also add your own lines.</p>
            <div class="mt-5">
                <label for="pick" class="label">Client</label>
                <select wire:model="pick" id="pick" class="select">
                    <option value="">Select a client...</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
                @error('pick') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit" class="btn btn-primary">Continue</button>
            </div>
        </form>
    @else
        @php $fmt = fn ($c) => \App\Support\Money::format($c, $summary['currency']); @endphp
        <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Work ready to bill</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Uninvoiced work for {{ $client->name }}. Untick anything you want to bill later; it stays here for the next invoice.</p>
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
                        <div class="mt-4 divide-y divide-slate-100 border-y border-slate-100 dark:divide-gray-700 dark:border-gray-700">
                            @foreach ($lines as $l)
                                @php $src = match (true) { $l->source_type === \App\Models\Task::class => 'Task', $l->source_type === \App\Models\Sprint::class => 'Sprint', $l->recurring_charge_id !== null => 'Recurring', default => 'Manual' }; @endphp
                                <div wire:key="line-{{ $l->id }}" class="flex items-center gap-3 py-3 text-sm">
                                    <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                                        <input type="checkbox" wire:model.live="selected" value="{{ $l->id }}" class="h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-slate-800 dark:text-gray-100">{{ $l->description }}</span>
                                            <span class="text-xs text-slate-500 dark:text-gray-400">{{ $src }}</span>
                                        </span>
                                        <span class="tabular-nums text-slate-800 dark:text-gray-100">{{ \App\Support\Money::format($l->amount_cents, $l->lineCurrency() ?? $client->currency) }}</span>
                                    </label>
                                    @unless ($l->source_type)
                                        <button type="button" wire:click="discardLine({{ $l->id }})" wire:confirm="Discard this pending line permanently?" class="btn btn-ghost px-2 text-slate-400 hover:text-rose-600" title="Discard line" aria-label="Discard line">&times;</button>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-4 rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 dark:border-gray-700 dark:text-gray-400">
                            Nothing waiting to be billed. Add a line below to invoice anything else.
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
                            <label for="currency" class="label">Currency</label>
                            <select wire:model.live="currency" id="currency" class="select">
                                @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                            </select>
                            @error('currency') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        @unless ($sameCurrency)
                            <div>
                                <label for="exchangeRate" class="label">Exchange rate to {{ $local }}</label>
                                <input wire:model.live.debounce.500ms="exchangeRate" id="exchangeRate" type="text" inputmode="decimal" placeholder="e.g. 83.5" class="input" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-gray-400">1 {{ $summary['currency'] }} in {{ $local }}. Locked on this invoice for your reports. Not shown to the client.</p>
                                @error('exchangeRate') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endunless
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
                    </div>

                    <dl class="space-y-2 border-t border-slate-100 pt-5 text-sm dark:border-gray-700">
                        <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Subtotal ({{ $summary['count'] }} {{ \Illuminate\Support\Str::plural('line', $summary['count']) }})</dt><dd class="tabular-nums">{{ $fmt($summary['subtotal']) }}</dd></div>
                        <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Tax</dt><dd class="tabular-nums">{{ $fmt($summary['tax']) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold text-gray-900 dark:border-gray-700 dark:text-white"><dt>Total</dt><dd class="tabular-nums" data-test="invoice-total">{{ $fmt($summary['subtotal'] + $summary['tax']) }}</dd></div>
                    </dl>

                    <div class="space-y-2">
                        <button type="submit" class="btn btn-primary w-full">Create &amp; send</button>
                        <button type="button" wire:click="saveDraft" class="btn btn-secondary w-full">Save as draft</button>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-gray-400"><strong class="font-medium">Send</strong> emails the invoice and adds it to the client's balance. A <strong class="font-medium">draft</strong> changes nothing until you send it from the invoice page.</p>
                </div>
            </div>
        </form>
    @endif
</div>
