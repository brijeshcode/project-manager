{{-- Task money (manage-money only): value, logged vs billed hours, and the optional per-task billing override. $task, $module, $project, $logged, $panel, $bv, $canEdit. --}}
@php
    $mode = $task->billingMode();
    $isHourly = $mode === 'hourly';
    $h = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') ?: '0';
    $editMode = $billingOverride ?: $module->billingMode();
    $rate = $task->rate();
@endphp
<x-ui.side-section title="Value" name="billing" :panel="$panel" :can="$canEdit">
    <p data-test="task-value">
        @if ($task->budget_amount === null)
            <span class="text-amber-600 dark:text-amber-400">Unpriced</span>
        @else
            {{ \App\Support\Money::format(\App\Support\Money::toCents((string) $task->budget_amount), $project->currency) }}
        @endif
        @if ($task->billedDifferently())
            <x-ui.badge color="slate" data-test="billing-tag">{{ $isHourly ? 'Hourly' : 'Fixed' }}</x-ui.badge>
        @endif
    </p>
    @if ($isHourly)
        <p class="text-xs text-slate-500" data-test="billing-line">Logged {{ $h($logged) }} h &middot; Billed {{ $h($task->billedHours()) }} h &times; {{ $rate === null ? 'no rate' : $h($rate) }} = {{ $task->budget_amount === null ? '—' : number_format((float) $task->budget_amount, 2) }}@if ($task->billable_hours !== null) <span class="text-slate-400">(adjusted)</span>@endif</p>
    @endif
    <x-slot:editor>
        <div class="space-y-2">
            @if ($editMode === 'hourly')
                <p class="text-xs text-slate-500" data-test="logged-hours">Logged {{ $h($logged) }} h</p>
                <div>
                    <label class="text-xs text-slate-500" for="billed-hours">Billed hours <span class="text-slate-400">(empty = logged hours)</span></label>
                    <input id="billed-hours" type="number" step="0.25" min="0" wire:model="billedHours" class="input h-8 text-sm" data-test="billed-hours-input">
                    @error('billedHours') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-slate-500" for="billed-reason">Reason</label>
                    <input id="billed-reason" type="text" wire:model="billedReason" placeholder="Required when changing the billed hours" class="input h-8 text-sm" data-test="billed-reason-input">
                    @error('billedReason') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-slate-500" for="task-rate">Hourly rate ({{ $project->currency }}) <span class="text-slate-400">(empty = the module's{{ $module->hourly_rate !== null ? ', '.$h($module->hourly_rate) : '' }})</span></label>
                    <input id="task-rate" type="text" wire:model="taskRate" class="input h-8 text-sm" data-test="task-rate-input">
                    @error('taskRate') <p class="field-error" data-test="rate-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div>
                    <label class="text-xs text-slate-500" for="task-amount">Budget amount ({{ $project->currency }}) <span class="text-slate-400">(empty = unpriced)</span></label>
                    <input id="task-amount" type="number" step="0.01" min="0" wire:model="budget_amount" class="input h-8 text-sm" data-test="amount-input">
                    @error('budget_amount') <p class="field-error" data-test="amount-error">{{ $message }}</p> @enderror
                </div>
            @endif
            @if ($showOverride)
                <div>
                    <label class="text-xs text-slate-500" for="billing-override">Billed differently</label>
                    <select id="billing-override" wire:model.live="billingOverride" class="select h-9 text-sm" data-test="billing-override">
                        <option value="">Same as module ({{ ucfirst($module->billingMode()) }})</option>
                        <option value="fixed">Fixed</option>
                        <option value="hourly">Hourly</option>
                    </select>
                </div>
            @else
                <button type="button" wire:click="$set('showOverride', true)" class="text-xs font-medium text-brand-600 hover:text-brand-700" data-test="billed-differently">Billed differently…</button>
            @endif
            @include('livewire.items._over-budget', ['bv' => $bv])
        </div>
    </x-slot:editor>
</x-ui.side-section>
