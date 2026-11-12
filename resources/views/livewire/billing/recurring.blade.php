@php
    $money = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
    $sums = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $money($c, $cur))->implode(' + ') ?: $money(0, $local);
    $foreign = fn ($byCur) => collect($byCur)->keys()->contains(fn ($cur) => strtoupper($cur) !== $local);
@endphp
<div>
    <x-ui.page-header title="Recurring services" subtitle="Hosting, retainers and other repeating fees. A billable line is added automatically each time one falls due.">
        <x-slot:actions>
            <a href="{{ route('billing.bank-accounts') }}" class="btn btn-ghost">Bank accounts</a>
            <a href="{{ route('billing.recurring.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="h-4 w-4" /> New service</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Active services" :value="$stats['active']" icon="refresh" color="green" :hint="$stats['paused'].' paused'" />
        <x-ui.stat label="Monthly recurring" :value="$sums($stats['monthly'])" icon="currency" color="blue" :hint="($foreign($stats['monthly']) ? ($stats['monthly_local'] !== null ? '≈ '.$money($stats['monthly_local'], $local) : '≈ '.$local.' needs an exchange rate').' · ' : '').'Active services, yearly spread per month, pass-through costs excluded'" />
        <x-ui.stat label="Due in 30 days" :value="$stats['due_soon']" icon="calendar" color="yellow" hint="Active services falling due" />
        <x-ui.stat label="Invoiced to date" :value="$sums($stats['invoiced'])" icon="check" color="purple" :hint="($foreign($stats['invoiced']) ? '≈ '.$money($stats['invoiced_local'], $local).' · ' : '').$stats['invoiced_count'].' '.\Illuminate\Support\Str::plural('time', $stats['invoiced_count']).' on sent invoices'" />
    </div>

    <div class="card mb-6 p-4">
        <div class="-mx-1 mb-4 flex gap-1 overflow-x-auto border-b border-slate-100 px-1 pb-3 dark:border-gray-700" role="tablist" aria-label="Filter by status">
            @foreach (['' => 'All', 'active' => 'Active', 'paused' => 'Paused'] as $value => $text)
                <button type="button" wire:click="$set('status', '{{ $value }}')" role="tab" aria-selected="{{ $status === $value ? 'true' : 'false' }}"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === $value ? 'bg-brand-50 text-brand-700 dark:bg-blue-900/30 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">{{ $text }}</button>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="search" class="sr-only">Search services</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by service, client or project..." class="input" />
            </div>
            <div>
                <label for="clientFilter" class="sr-only">Client</label>
                <select wire:model.live="clientFilter" id="clientFilter" class="select">
                    <option value="">All clients</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($charges->isEmpty())
        @if ($search !== '' || $status !== '' || $clientFilter !== '')
            <x-ui.empty-state title="No matches" message="No services match your filters." name="refresh">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No recurring services yet" message="Add hosting, maintenance or a retainer once and it is billed on schedule." name="refresh">
                <x-slot name="action"><a href="{{ route('billing.recurring.create') }}" wire:navigate class="btn btn-primary">New service</a></x-slot>
            </x-ui.empty-state>
        @endif
    @else
        @php $ids = $charges->pluck('id')->map(fn ($id) => (string) $id)->all(); $box = 'h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700'; @endphp
        @if ($selected)
            <div class="card mb-4 flex flex-wrap items-center gap-2 p-3" data-test="bulk-bar">
                <span class="mr-auto text-sm font-medium text-gray-900 dark:text-white">{{ count($selected) }} selected</span>
                <button type="button" wire:click="bulk('skip')" class="btn btn-secondary">Skip next period</button>
                <button type="button" wire:click="bulk('pause')" class="btn btn-secondary">Pause</button>
                <button type="button" wire:click="bulk('resume')" class="btn btn-secondary">Resume</button>
                <button type="button" wire:click="$set('selected', [])" class="btn btn-ghost">Clear</button>
            </div>
        @endif
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th w-px"><input type="checkbox" aria-label="Select all on this page" class="{{ $box }}" @checked(! array_diff($ids, $selected)) x-on:change="$wire.set('selected', $event.target.checked ? @js($ids) : [])" /></th>
                        <th class="table-th">Service</th>
                        <th class="table-th">Client</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Next due</th>
                        <th class="table-th text-right">Invoiced</th>
                        <th class="table-th">Status</th>
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($charges as $c)
                        @php $cur = $c->currency(); @endphp
                        <tr wire:key="rc-{{ $c->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40" data-test="recurring-row">
                            <td class="table-td w-px"><input type="checkbox" wire:model.live="selected" value="{{ $c->id }}" aria-label="Select {{ $c->description }}" class="{{ $box }}" /></td>
                            <td class="table-td">
                                <a href="{{ route('billing.recurring.show', $c) }}" wire:navigate class="link font-medium">{{ $c->description }}</a>
                                @if ($c->pass_through)<x-ui.badge class="ml-1" title="A cost passed on to the client: billed as usual, not counted as income">Pass-through</x-ui.badge>@endif
                                <div class="text-xs text-slate-500 dark:text-gray-400">{{ ucfirst($c->frequency->value) }}</div>
                            </td>
                            <td class="table-td">
                                <div class="text-gray-900 dark:text-white">{{ $c->client->name }}</div>
                                <div class="text-xs text-slate-500 dark:text-gray-400">{{ $c->project?->name ?? 'Client-wide' }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $money($c->amount_cents, $cur) }}</span>
                                <div class="text-xs text-slate-500 dark:text-gray-400">per {{ $c->frequency->value === 'yearly' ? 'year' : 'month' }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap">
                                @if ($c->active)
                                    <span @class(['font-medium text-amber-600 dark:text-amber-400' => $c->next_due_on->lte(today()->addDays(7))])>{{ $c->next_due_on->toFormattedDateString() }}</span>
                                @else
                                    <span class="text-slate-400 dark:text-gray-500">—</span>
                                @endif
                                @php $gaps = array_filter([count($c->skipped_periods ?? []) ? count($c->skipped_periods).' skipped' : null, count($c->paused_periods ?? []) ? count($c->paused_periods).' paused' : null]); @endphp
                                @if ($gaps)<div class="text-xs text-slate-500 dark:text-gray-400" data-test="gaps">{{ implode(' · ', $gaps) }}</div>@endif
                            </td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums" data-test="usage">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $money($c->invoiced_cents, $cur) }}</div>
                                @if ($c->invoiced_count && strtoupper($cur) !== $local)
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ $money($c->invoiced_local_cents, $local) }}</div>
                                @endif
                                <div class="text-xs text-slate-500 dark:text-gray-400">{{ $c->invoiced_count }}× invoiced{{ $c->draft_count ? ' · '.$c->draft_count.' in draft' : '' }}</div>
                            </td>
                            <td class="table-td"><x-ui.badge :color="$c->statusBadge()[1]">{{ $c->statusBadge()[0] }}</x-ui.badge></td>
                            <td class="table-td whitespace-nowrap text-right">
                                <button type="button" wire:click="toggle({{ $c->id }})" class="btn btn-ghost px-2" title="{{ $c->active ? 'Pause' : 'Resume' }}" aria-label="{{ ($c->active ? 'Pause ' : 'Resume ').$c->description }}"><x-icon :name="$c->active ? 'ban' : 'play'" class="h-4 w-4" /></button>
                                <a href="{{ route('billing.recurring.show', $c) }}" wire:navigate class="btn btn-ghost px-2" title="View details" aria-label="View {{ $c->description }}"><x-icon name="eye" class="h-4 w-4" /></a>
                                <a href="{{ route('billing.recurring.edit', $c) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $c->description }}"><x-icon name="pencil" class="h-4 w-4" /></a>
                                @if ($c->invoiced_count + $c->draft_count === 0)
                                    <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Delete this service? Any lines it added that are not on an invoice are removed too." class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $c->description }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $charges->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
