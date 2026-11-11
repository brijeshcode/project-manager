{{-- One editable invoice row, aligned with _items-head. Defaults to custom.$i; edit passes $model/$remove/$amount/$tag for its own manual lines. --}}
@php
    $showQty ??= true;
    $model ??= 'custom.'.$i;
    $remove ??= 'removeCustom('.$i.')';
    $amount ??= $summary['custom'][$i] ?? 0;
    $tag ??= 'Custom item';
    $key = str_replace('.', '-', $model);
@endphp
<div wire:key="{{ $key }}" class="flex flex-wrap items-start gap-3 px-4 py-3 sm:flex-nowrap">
    <div class="min-w-0 basis-full sm:basis-auto sm:flex-1">
        <label for="{{ $key }}-description" class="sr-only">Description</label>
        <input wire:model.live.debounce.500ms="{{ $model }}.description" id="{{ $key }}-description" type="text" maxlength="255" placeholder="Description, e.g. Domain renewal, 1 year" class="input" />
        <p class="mt-1 text-xs text-slate-400 dark:text-gray-500">{{ $tag }}</p>
        @error("$model.description") <p class="field-error">{{ $message }}</p> @enderror
    </div>
    @if ($showQty)
        <div class="w-20 shrink-0">
            <label for="{{ $key }}-quantity" class="sr-only">Quantity</label>
            <input wire:model.live.debounce.500ms="{{ $model }}.quantity" id="{{ $key }}-quantity" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="Qty" class="input text-right" />
            @error("$model.quantity") <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="w-32 shrink-0">
            <label for="{{ $key }}-unit_price" class="sr-only">Unit price</label>
            <input wire:model.live.debounce.500ms="{{ $model }}.unit_price" id="{{ $key }}-unit_price" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="e.g. 1500" class="input text-right" />
            @error("$model.unit_price") <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex h-10 w-32 shrink-0 items-center justify-end text-sm font-medium tabular-nums text-slate-800 dark:text-gray-100">{{ $fmt($amount) }}</div>
    @else
        <div class="w-36 shrink-0">
            <label for="{{ $key }}-unit_price" class="sr-only">Amount</label>
            <input wire:model.live.debounce.500ms="{{ $model }}.unit_price" id="{{ $key }}-unit_price" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="e.g. 1500" class="input text-right" />
            @error("$model.unit_price") <p class="field-error">{{ $message }}</p> @enderror
        </div>
    @endif
    <button type="button" wire:click="{{ $remove }}" class="btn btn-ghost h-10 w-10 shrink-0 justify-center px-0 text-slate-400 hover:text-rose-600" title="Remove" aria-label="Remove item">&times;</button>
</div>
