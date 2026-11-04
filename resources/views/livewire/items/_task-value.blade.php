{{-- A task's money in a list row (manage-money only): its value, a Fixed/Hourly tag when billed differently from its module, or Unpriced. --}}
@if ($task->budget_amount === null)
    <x-ui.badge color="amber" data-test="row-unpriced">Unpriced</x-ui.badge>
@else
    <span class="text-xs tabular-nums text-slate-600 dark:text-gray-300" data-test="row-value">{{ \App\Support\Money::format(\App\Support\Money::toCents((string) $task->budget_amount), $currency) }}</span>
@endif
@if ($task->billedDifferently())
    <x-ui.badge color="slate" data-test="row-billing">{{ $task->billingMode() === 'hourly' ? 'Hourly' : 'Fixed' }}</x-ui.badge>
@endif
