@php
    $fmt = fn ($c) => \App\Support\Money::format($c, $summary['currency'] ?? \App\Services\LocalCurrency::code());
    $raw = fn ($lines) => \App\Support\Money::format((int) $lines->sum('amount_cents'), $lines->first()?->lineCurrency() ?? $client?->currency ?? 'USD');
    $h2 = 'text-base font-semibold text-gray-900 dark:text-white';
    $hint = 'mt-0.5 text-sm text-slate-500 dark:text-gray-400';
    $row = 'flex items-start gap-3 px-4 py-3 text-sm';
    $box = 'mt-1 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-700';
    $empty = 'rounded-lg border border-dashed border-slate-200 px-4 py-5 text-center text-sm text-slate-500 dark:border-gray-700 dark:text-gray-400';
    $ticked = count($pickSprints) + count($pickModules) + count($pickTasks) + count($pickCharges) + count($pickSubs);
@endphp
<div class="mx-auto max-w-5xl">
    <x-ui.page-header title="New invoice" :subtitle="$client ? 'Billing '.$client->name : 'Choose who you are billing'">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6">
        {{-- 1. Who and when --}}
        <div class="card p-6">
            <h2 class="{{ $h2 }}">Invoice details</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="pick" class="label">Client</label>
                    <select wire:model.live="pick" id="pick" class="select">
                        <option value="">Select a client...</option>
                        @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @error('pick') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="issuedOn" class="label">Invoice date</label>
                    <input wire:model.live="issuedOn" id="issuedOn" type="date" max="{{ now()->addYear()->toDateString() }}" class="input" />
                    @error('issuedOn') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bankAccountId" class="label">Pay to</label>
                    <select wire:model="bankAccountId" id="bankAccountId" class="select">
                        <option value="">No bank details</option>
                        @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                    </select>
                    @error('bankAccountId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                @if ($client)
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
                            <input wire:model.live.debounce.500ms="exchangeRate" id="exchangeRate" type="number" min="0.000001" step="0.000001" inputmode="decimal" placeholder="e.g. 83.5" class="input" />
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-gray-400">1 {{ $summary['currency'] }} in {{ $local }}, for your reports only.</p>
                            @error('exchangeRate') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endunless
                    <div>
                        <label for="taxPercent" class="label">Tax %</label>
                        <input wire:model.live.debounce.500ms="taxPercent" id="taxPercent" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="e.g. 18" class="input" />
                        @error('taxPercent') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
            @if ($client)
                <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                    <p class="text-slate-500 dark:text-gray-400">
                        Terms <span class="font-medium text-slate-700 dark:text-gray-200">{{ $client->payment_terms ?: 'due on receipt' }}</span>
                        &middot; emails to <span class="font-medium {{ $client->contact_email ? 'text-slate-700 dark:text-gray-200' : 'text-amber-600 dark:text-amber-400' }}">{{ $client->contact_email ?: 'no email on file' }}</span>
                    </p>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-slate-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.live="showQuantity" class="h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        Show quantity and unit price columns
                    </label>
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500 dark:text-gray-400">Pick a client and their completed, unbilled work appears below.</p>
            @endif
            <p wire:loading wire:target="pick" class="mt-2 text-sm text-slate-500 dark:text-gray-400">Loading client's work...</p>
        </div>

        @if ($client)
            <div wire:loading.class="opacity-50" wire:target="pick" class="space-y-6">
                {{-- 2. Pick completed, unbilled work --}}
                @include('livewire.invoices._picker')
                @include('livewire.invoices._items')
                @include('livewire.invoices._aside')

                {{-- 5. Finish --}}
                <div class="card sticky bottom-4 z-10 flex flex-col gap-3 p-4 shadow-lg sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600 dark:text-gray-300">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $fmt($summary['subtotal'] + $summary['tax']) }}</span>
                        <span class="hidden sm:inline">&middot; Send emails it and adds it to the balance; a draft changes nothing until sent.</span>
                    </p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="saveDraft" class="btn btn-secondary">Save as draft</button>
                        <button type="submit" class="btn btn-primary">Create &amp; send</button>
                    </div>
                </div>
            </div>
        @endif
    </form>
</div>
