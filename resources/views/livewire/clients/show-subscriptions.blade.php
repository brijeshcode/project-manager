<div class="space-y-4">
    @php
        $subStatuses = ['active' => 'Active', 'paused' => 'Paused'];
        $subActive = $subscriptions->where('active', true);
        $subMonthly = $subActive->reject->pass_through->groupBy('display_currency')->map->sum(fn ($c) => $c->monthlyCents());
        $subRates = $subMonthly->map(fn ($cents, $cur) => \App\Services\LocalCurrency::rateFor($cur));
        $subMonthlyLocal = $subRates->contains(null) ? null : $subMonthly->map(fn ($cents, $cur) => \App\Support\Money::convert($cents, $subRates[$cur]))->sum();
        $subInvoiced = $subscriptions->groupBy('display_currency')->map->sum('total_invoiced')->filter()->all();
        $subTimes = $subscriptions->sum('times_invoiced');
    @endphp
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="client-subscription-stats">
        <x-ui.stat compact label="Active services" :value="$subActive->count()" icon="refresh" color="green" :hint="($subscriptions->count() - $subActive->count()).' paused'" />
        <x-ui.stat compact label="Monthly recurring" :value="$smSum($subMonthly->all())" icon="currency" color="blue"
            :hint="($isForeign($subMonthly->all()) ? ($subMonthlyLocal !== null ? '≈ '.$sm($subMonthlyLocal, $localCode) : '≈ '.$localCode.' needs an exchange rate').' · ' : '').'Active, yearly spread per month, pass-through excluded'" />
        <x-ui.stat compact label="Due in 30 days" :value="$subActive->filter(fn ($c) => $c->next_due_on?->lte(today()->addDays(30)))->count()" icon="calendar" color="yellow" hint="Active services falling due" />
        <x-ui.stat compact label="Invoiced to date" :value="$smSum($subInvoiced)" icon="check" color="purple"
            :hint="($isForeign($subInvoiced) ? '≈ '.$sm($subscriptions->sum('invoiced_local_cents'), $localCode).' · ' : '').$subTimes.' '.Str::plural('time', $subTimes).' on sent invoices'" />
    </div>
    <div class="flex items-center justify-between gap-2">
        <p class="text-sm text-gray-500 dark:text-gray-400">Recurring services billed to this client.</p>
        <div class="flex items-center gap-2">
            <x-ui.filter-button for="client-subscriptions" :active="($subscriptionSearch !== '') + isset($subStatuses[$subscriptionStatus])" />
            <a href="{{ route('billing.recurring.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary"><x-nav.icon name="plus" class="h-5 w-5" /> Add subscription</a>
        </div>
    </div>

    <x-ui.filter-panel for="client-subscriptions" class="!mb-0">
        <div class="sm:w-80">
            <label for="subscriptionSearch" class="sr-only">Search subscriptions</label>
            <input wire:model="subscriptionSearch" id="subscriptionSearch" type="search" maxlength="100" placeholder="Search by service or project..." class="input" />
        </div>
        <div class="sm:w-60">
            <label for="subscriptionStatus" class="sr-only">Status</label>
            <select wire:model="subscriptionStatus" id="subscriptionStatus" class="select">
                @foreach (['' => 'All statuses'] + $subStatuses as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <x-slot:chips>
            @if ($subscriptionSearch !== '')<x-ui.filter-chip :label="'Search: '.$subscriptionSearch" wire:click="$set('subscriptionSearch', '')" />@endif
            @isset($subStatuses[$subscriptionStatus])<x-ui.filter-chip :label="'Status: '.$subStatuses[$subscriptionStatus]" wire:click="$set('subscriptionStatus', '')" />@endisset
        </x-slot:chips>
    </x-ui.filter-panel>

    @if ($subscriptions->isEmpty() && ($subscriptionSearch !== '' || isset($subStatuses[$subscriptionStatus])))
        <x-ui.empty-state title="No matches" message="No subscriptions match your filters." name="refresh" />
    @elseif ($subscriptions->isEmpty())
        <x-ui.empty-state title="No subscriptions yet" message="Recurring services for this client, like hosting or maintenance, will appear here." name="refresh" />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="subscriptions">
                <thead>
                    <tr>
                        <th class="table-th">Description</th><th class="table-th">Project</th><th class="table-th">Frequency</th><th class="table-th text-right">Amount</th>
                        <th class="table-th">Next due</th><th class="table-th">Status</th><th class="table-th text-right">Invoiced</th><th class="table-th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($subscriptions as $s)
                        <tr wire:key="sub-{{ $s->id }}">
                            <td class="table-td"><a href="{{ route('billing.recurring.show', ['charge' => $s->id]) }}" wire:navigate class="link font-medium">{{ $s->description }}</a></td>
                            <td class="table-td">{{ $s->project?->name ?? 'Client-wide' }}</td>
                            <td class="table-td">{{ ucfirst($s->frequency->value) }}</td>
                            <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($s->amount_cents, $s->display_currency) }}</td>
                            <td class="table-td whitespace-nowrap">{{ $s->active ? $s->next_due_on?->format('M j, Y') : '—' }}</td>
                            <td class="table-td"><x-ui.badge :color="$s->statusBadge()[1]">{{ $s->statusBadge()[0] }}</x-ui.badge></td>
                            <td class="table-td text-right tabular-nums">
                                {{ \App\Support\Money::format($s->total_invoiced, $s->display_currency) }}
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $s->times_invoiced }} {{ Str::plural('time', $s->times_invoiced) }}</div>
                            </td>
                            @include('livewire.clients._row-actions', ['edit' => route('billing.recurring.edit', ['charge' => $s->id]), 'method' => 'deleteSubscription', 'id' => $s->id, 'title' => 'Delete subscription', 'text' => 'Delete "'.$s->description.'"? Lines not yet on an invoice are removed too. Subscriptions already on invoices keep their history and cannot be deleted; pause them instead.'])
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
