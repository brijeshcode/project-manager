{{-- The over-budget block: message plus the three explicit ways out. $bv comes from HandlesBudgetBlock::blockView(). --}}
@if ($bv)
    <div class="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200" role="alert" data-test="over-budget">
        <div class="flex items-start justify-between gap-2">
            <p class="font-medium" data-test="over-budget-message">{{ $bv['message'] }}</p>
            <button type="button" wire:click="dismissBlock" class="text-rose-500 hover:text-rose-700" aria-label="Dismiss" data-test="dismiss-block"><x-icon name="x" class="h-4 w-4" /></button>
        </div>
        @if ($bv['money'])
            <p class="mt-1 text-xs">Nothing was saved. Choose one:</p>
            <div class="mt-2 space-y-2.5">
                <div data-test="opt-raise">
                    <p class="text-xs font-semibold">{{ $bv['b']['kind'] === 'module' ? '1. Set the module budget to '.$bv['raiseTo'].' instead (the most the tasks allow you to lower it to)' : '1. Raise the module budget to '.$bv['raiseTo'] }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="blockReason" aria-label="Reason" placeholder="Reason" class="input h-8 min-w-0 flex-1 text-xs" data-test="block-reason">
                        <button type="button" wire:click="raiseBlocked" class="btn btn-primary h-8 px-2.5 py-1 text-xs" data-test="raise-budget">{{ $bv['b']['kind'] === 'module' ? 'Set budget' : 'Raise budget' }}</button>
                    </div>
                    @error('blockReason') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div data-test="opt-addon">
                    <p class="text-xs font-semibold">2. Create an add-on task</p>
                    <a href="{{ $bv['addonUrl'] }}" wire:navigate class="btn btn-secondary mt-1 h-8 px-2.5 py-1 text-xs" data-test="create-addon">Create add-on</a>
                </div>
                <div data-test="opt-rebalance">
                    <p class="text-xs font-semibold">3. Rebalance the other tasks</p>
                    @if ($bv['hourly'])
                        <p class="mt-1 text-xs">Not available: an hourly client is billed for hours worked, so values are not rebalanced.</p>
                    @elseif (! $bv['rebalance']['ok'])
                        <p class="mt-1 text-xs" data-test="rebalance-why">Not available: {{ $bv['rebalance']['reason'] }}</p>
                        <button type="button" class="btn btn-secondary mt-1 h-8 px-2.5 py-1 text-xs" disabled data-test="rebalance">Rebalance</button>
                    @elseif (! $bv['preview'])
                        <button type="button" wire:click="showRebalance" class="btn btn-secondary mt-1 h-8 px-2.5 py-1 text-xs" data-test="rebalance">Preview rebalance</button>
                    @else
                        <table class="mt-1 w-full text-xs" data-test="rebalance-preview">
                            <thead><tr class="text-left text-rose-700 dark:text-rose-300"><th class="py-0.5">Task</th><th class="text-right">Now</th><th class="text-right">New</th></tr></thead>
                            @foreach ($bv['rebalance']['rows'] as $r)
                                <tr><td class="truncate py-0.5">{{ $r['task']->code() }} {{ $r['task']->title }}</td><td class="text-right tabular-nums">{{ $bv['mb']->format($r['old']) }}</td><td class="text-right font-semibold tabular-nums">{{ $bv['mb']->format($r['new']) }}</td></tr>
                            @endforeach
                        </table>
                        <p class="mt-1 text-xs">Done tasks keep their value. Applies only when you confirm.</p>
                        <button type="button" wire:click="applyBlockedRebalance" class="btn btn-primary mt-1 h-8 px-2.5 py-1 text-xs" data-test="apply-rebalance">Apply rebalance and save</button>
                    @endif
                    @error('block') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        @else
            <p class="mt-1 text-xs">Nothing was saved. Ask an administrator to raise the budget or rebalance the module.</p>
        @endif
    </div>
@endif
