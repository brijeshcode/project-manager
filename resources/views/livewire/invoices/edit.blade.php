@php
    $fmt = fn ($c) => \App\Support\Money::format($c, $summary['currency']);
    $raw = fn ($lines) => \App\Support\Money::format((int) $lines->sum('amount_cents'), $lines->first()?->lineCurrency() ?? $client->currency);
    $h2 = 'text-base font-semibold text-gray-900 dark:text-white';
    $hint = 'mt-0.5 text-sm text-slate-500 dark:text-gray-400';
    $row = 'flex items-start gap-3 px-4 py-3 text-sm';
    $box = 'mt-1 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-700';
    $empty = 'rounded-lg border border-dashed border-slate-200 px-4 py-5 text-center text-sm text-slate-500 dark:border-gray-700 dark:text-gray-400';
    $ticked = count($pickSprints) + count($pickModules) + count($pickTasks) + count($pickCharges) + count($pickSubs);
@endphp
<div class="mx-auto max-w-5xl">
    <x-ui.page-header :title="'Edit invoice '.$invoice->number" :subtitle="$client->name.' · '.$invoice->currency">
        <x-slot:actions>
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6">
        {{-- 1. Dates, terms and money settings --}}
        <div class="card p-6">
            <h2 class="{{ $h2 }}">Invoice details</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="issuedOn" class="label">Invoice date</label>
                    <input wire:model.live="issuedOn" id="issuedOn" type="date" class="input" />
                    @error('issuedOn') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="dueOn" class="label">Due date</label>
                    <input wire:model="dueOn" id="dueOn" type="date" class="input" />
                    @error('dueOn') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="terms" class="label">Terms</label>
                    <input wire:model="terms" id="terms" type="text" maxlength="255" placeholder="e.g. net-30" class="input" />
                    @error('terms') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bankAccountId" class="label">Pay to</label>
                    <select wire:model="bankAccountId" id="bankAccountId" class="select">
                        <option value="">No bank details</option>
                        @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->label }} ({{ $b->bank_name }})</option>@endforeach
                    </select>
                    @error('bankAccountId') <p class="field-error">{{ $message }}</p> @enderror
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
            </div>
            <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                <p class="text-slate-500 dark:text-gray-400">
                    Client <span class="font-medium text-slate-700 dark:text-gray-200">{{ $client->name }}</span>
                    &middot; currency <span class="font-medium text-slate-700 dark:text-gray-200">{{ $invoice->currency }}</span>
                </p>
                <label class="inline-flex cursor-pointer items-center gap-2 text-slate-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.live="showQuantity" class="h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                    Show quantity and unit price columns
                </label>
            </div>
        </div>

        @include('livewire.invoices._picker')
        @include('livewire.invoices._items')
        @include('livewire.invoices._aside')

        <div class="card sticky bottom-4 z-10 flex flex-col gap-3 p-4 shadow-lg sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-600 dark:text-gray-300">
                <span class="font-semibold text-gray-900 dark:text-white">{{ $fmt($summary['subtotal'] + $summary['tax']) }}</span>
                <span class="hidden sm:inline">&middot; {{ $invoice->status === 'draft' ? 'Still a draft: saving does not send it.' : "Saving updates the client's balance." }} Work you removed becomes billable again.</span>
            </p>
            <div class="flex gap-2">
                <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </div>
    </form>
</div>
