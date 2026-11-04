{{-- The over-budget block: the message plus the one action. $bv comes from HandlesBudgetBlock::blockView(). --}}
@if ($bv)
    <div class="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200" role="alert" data-test="over-budget">
        <div class="flex items-start justify-between gap-2">
            <p class="font-medium" data-test="over-budget-message">{{ $bv['message'] }}</p>
            <button type="button" wire:click="dismissBlock" class="text-rose-500 hover:text-rose-700" aria-label="Dismiss" data-test="dismiss-block"><x-icon name="x" class="h-4 w-4" /></button>
        </div>
        @if ($bv['money'])
            <p class="mt-1 text-xs">Nothing was saved.</p>
            <div class="mt-2" data-test="opt-raise">
                <label class="text-xs font-semibold" for="block-reason">Reason</label>
                <input id="block-reason" type="text" wire:model="blockReason" class="input mt-0.5 h-8 w-full text-xs" data-test="block-reason">
                @error('blockReason') <p class="field-error">{{ $message }}</p> @enderror
                <button type="button" wire:click="raiseBlocked" class="btn btn-primary mt-2 h-8 px-2.5 py-1 text-xs" data-test="raise-budget">{{ $bv['b']['kind'] === 'module' ? 'Set the module amount to '.$bv['raiseTo'] : 'Raise the module amount to '.$bv['raiseTo'] }}</button>
            </div>
        @else
            <p class="mt-1 text-xs">Nothing was saved. Ask an administrator to raise the module amount.</p>
        @endif
    </div>
@endif
