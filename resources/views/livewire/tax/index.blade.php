<div>
    <x-ui.page-header title="Tax" :subtitle="'TDS your clients deducted, and a rough tax estimate. Only you see this. Local currency: '.$local">
        <x-slot:actions>
            <label for="fy" class="sr-only">Financial year</label>
            <select wire:model.live="fy" id="fy" class="select">
                @foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}-{{ substr($y + 1, 2) }}</option>@endforeach
            </select>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $m = fn ($c) => \App\Support\Money::format($c, $local);
        $r = fn ($rupees) => \App\Support\Money::format($rupees * 100, $local);
        $tds = $deductedTotal;
    @endphp

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.stat label="TDS & deductions by clients" :value="$m($deductedTotal)" color="blue" icon="document"
            :hint="$deducted->sum('count').' payments in FY '.$fy.'-'.substr($fy + 1, 2).($missingRate ? ' · '.$missingRate.' without a rate, not counted' : '')" />
        <x-ui.stat label="Tax, new regime" :value="$r($estimate['new']['total'])" color="green" icon="document" :hint="'Estimate · after TDS: '.$m($estimate['new']['total'] * 100 - $tds)" />
        <x-ui.stat label="Tax, old regime" :value="$r($estimate['old']['total'])" color="yellow" icon="document" :hint="'Estimate · after TDS: '.$m($estimate['old']['total'] * 100 - $tds)" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="tds-table">
                <thead><tr><th class="table-th">Client</th><th class="table-th text-right">Payments</th><th class="table-th text-right">TDS &amp; deductions</th></tr></thead>
                <tbody class="divide-y divide-slate-200 dark:divide-gray-700">
                    @forelse ($deducted as $row)
                        <tr><td class="table-td">{{ $row['client'] }}</td><td class="table-td text-right tabular-nums">{{ $row['count'] }}</td><td class="table-td text-right tabular-nums">{{ $m($row['cents']) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="table-td text-center text-gray-500">No TDS recorded in this financial year.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>

        <x-ui.card>
            <h2 class="mb-1 font-semibold text-gray-900 dark:text-white">Rough estimate</h2>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">Nothing here is saved. Receipts and expenses start from your books; change anything. Whole {{ $local }}.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="receipts" class="label">Business receipts</label><input wire:model.live.debounce.400ms="receipts" id="receipts" inputmode="numeric" class="input tabular-nums" /></div>
                <div><label for="expenses" class="label">Business expenses</label><input wire:model.live.debounce.400ms="expenses" id="expenses" inputmode="numeric" class="input tabular-nums" @disabled($presumptive) /></div>
                <div><label for="other" class="label">Other income</label><input wire:model.live.debounce.400ms="other" id="other" inputmode="numeric" placeholder="0" class="input tabular-nums" /></div>
                <div><label for="deductions" class="label">Old regime deductions (80C, 80D…)</label><input wire:model.live.debounce.400ms="deductions" id="deductions" inputmode="numeric" placeholder="0" class="input tabular-nums" /></div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="presumptive" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600" />Presumptive (44ADA): taxable business income is 50% of receipts</label>

            <table class="mt-5 w-full text-sm" data-test="tax-estimate">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="py-1 font-medium"></th><th class="py-1 text-right font-medium">New regime</th><th class="py-1 text-right font-medium">Old regime</th></tr></thead>
                <tbody class="tabular-nums text-gray-900 dark:text-white">
                    @foreach (['taxable' => 'Taxable income', 'tax' => 'Tax', 'surcharge' => 'Surcharge', 'cess' => 'Cess (4%)', 'total' => 'Total tax'] as $k => $label)
                        <tr @class(['border-t border-gray-200 font-semibold dark:border-gray-700' => $k === 'total'])><td class="py-1">{{ $label }}</td><td class="py-1 text-right">{{ $r($estimate['new'][$k]) }}</td><td class="py-1 text-right">{{ $r($estimate['old'][$k]) }}</td></tr>
                    @endforeach
                    <tr><td class="py-1">Less TDS</td><td class="py-1 text-right">-{{ $m($tds) }}</td><td class="py-1 text-right">-{{ $m($tds) }}</td></tr>
                    <tr class="border-t border-gray-200 font-semibold dark:border-gray-700"><td class="py-1">Still to pay (negative = refund)</td><td class="py-1 text-right">{{ $m($estimate['new']['total'] * 100 - $tds) }}</td><td class="py-1 text-right">{{ $m($estimate['old']['total'] * 100 - $tds) }}</td></tr>
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">Individual under 60. Includes 87A rebate, surcharge and cess; FY 2026-27 assumes the 2025-26 slabs. Confirm with your CA before filing.</p>
        </x-ui.card>
    </div>
</div>
