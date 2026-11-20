@php
    $balance = $received - $billed;
    $localHint = fn (?int $l, int $m) => $m > 0 ? 'Local total unavailable — '.$m.' without an exchange rate' : ($l ? \App\Support\Money::format($l, $localCode) : null);
    $fmt = fn (int $c) => \App\Support\Money::format($c, $client->currency);
    $addressLine = collect([$client->city, $client->state, $client->postal_code, $client->country])->filter()->implode(', ');
    // Details tab: value = string, or [text, href] for a link; empty shows a muted dash so the grids stay even
    $link = fn (?string $v, string $href) => $v ? [$v, $href] : null;
    $site = $client->website ? (preg_match('#^https?://#i', $client->website) ? $client->website : 'https://'.$client->website) : null;
    $sections = [
        ['Basic information', 'tag', 'sm:grid-cols-3', [
            'Client type' => ucfirst($client->type->value), 'Company / legal name' => $client->company_name, 'Client code' => $client->client_code, 'Status' => 'status',
            'Billing mode' => ucfirst($client->billing_mode->value), 'Currency' => $client->currency,
        ]],
        ['Contact', 'user', 'sm:grid-cols-2', [
            'Contact person' => $client->contact_name, 'Email' => $link($client->contact_email, 'mailto:'.$client->contact_email),
            'Phone' => $link($client->contact_phone, 'tel:'.preg_replace('/[^\d+]/', '', (string) $client->contact_phone)), 'Website' => $link($client->website, (string) $site),
        ]],
        ['Billing', 'landmark', 'sm:grid-cols-2', [
            'Tax / VAT number' => $client->tax_number, 'Payment terms' => $client->payment_terms, 'Billing address' => $client->billing_address,
        ]],
    ];
    $dt = 'text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-gray-400';
    $dd = 'mt-1 whitespace-pre-line break-words text-sm font-medium text-gray-900 dark:text-white';
    $tabs = ['details' => 'Details', 'projects' => 'Projects', 'invoices' => 'Invoices', 'payments' => 'Payments'] + ($statement ? ['statement' => 'Statement', 'subscriptions' => 'Subscriptions'] : []) + ['access' => 'Portal access'];
    // money stat rows on the list tabs
    $sm = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur);
    $smSum = fn ($byCur) => collect($byCur)->map(fn ($c, $cur) => $sm($c, $cur))->implode(' + ') ?: $sm(0, $localCode);
    $isForeign = fn ($byCur) => collect($byCur)->keys()->contains(fn ($cur) => strtoupper($cur) !== $localCode);
@endphp
<div x-data="{ tab: (h => @js(array_keys($tabs)).includes(h) ? h : 'details')(location.hash.slice(1)), del: null, ask: null }"
     x-init="$watch('tab', t => history.replaceState(history.state, '', t === 'details' ? location.pathname + location.search : '#' + t))"
     class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-4">
            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400">
                <x-nav.icon :name="$client->type->value === 'company' ? 'building' : 'user'" class="h-8 w-8" />
            </span>
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $client->name }}</h1>
                @if ($client->company_name)<p class="text-sm text-gray-500 dark:text-gray-400" data-test="client-company">{{ $client->company_name }}</p>@endif
                <div class="mt-1 flex items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $client->client_code }}</span>
                    <select wire:change="setStatus($event.target.value)" aria-label="Client status" class="select h-7 w-auto py-0 pl-2 pr-7 text-xs capitalize" data-test="client-status">
                        @foreach (\App\Enums\ClientStatus::cases() as $s)
                            <option value="{{ $s->value }}" @selected($client->status === $s)>{{ ucfirst($s->value) }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($client->contact_name)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Contact: {{ $client->contact_name }}</p>
                @endif
            </div>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-3">
            <a href="{{ route('projects.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary"><x-nav.icon name="plus" class="h-5 w-5" /> Add Project</a>
            <a href="{{ route('clients.edit', $client) }}" wire:navigate class="btn btn-secondary"><x-nav.icon name="pencil" class="h-5 w-5" /> Edit Client</a>
            <button type="button" class="btn btn-danger" x-on:click="del = { id: {{ $client->id }}, name: @js($client->name) }"><x-nav.icon name="trash" class="h-5 w-5" /> Delete</button>
            <a href="{{ route('clients.index') }}" wire:navigate class="btn btn-secondary"><x-nav.icon name="arrow-left" class="h-5 w-5" /> Back to Clients</a>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="card">
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex gap-8 overflow-x-auto px-6" aria-label="Tabs" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:text-gray-300'"
                            class="whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors">{{ $label }}</button>
                @endforeach
            </nav>
        </div>

        <div role="tabpanel" x-show="tab === 'details'" class="space-y-6 bg-slate-50/60 p-4 sm:p-6 dark:bg-gray-900/20" data-test="client-details">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3" data-test="client-financials">
                <x-ui.stat label="Total Billed" :value="$fmt($billed)" :hint="$localHint($billedLocal, $billedMissing)" icon="currency" color="green" />
                <x-ui.stat label="Total Received" :value="$fmt($received)" :hint="$localHint($receivedLocal, $receivedMissing)" icon="credit-card" color="blue" />
                <div class="card p-5">
                    <div class="flex items-center gap-4">
                        @if ($balance < 0)
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400"><x-nav.icon name="alert" class="h-6 w-6" /></div>
                        @else
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400"><x-nav.icon name="check" class="h-6 w-6" /></div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current Balance</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums {{ $balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $fmt($balance) }}</p>
                            @if ($balance < 0)<p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Owing</p>@endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @foreach ($sections as [$title, $icon, $cols, $fields])
                    <section class="card p-6">
                        @include('livewire.clients._details-head')
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-5 {{ $cols }}">
                            @foreach ($fields as $label => $value)
                                <div @class(['min-w-0', 'sm:col-span-2' => $label === 'Billing address'])>
                                    <dt class="{{ $dt }}">{{ $label }}</dt>
                                    <dd class="{{ $dd }}">
                                        @if ($value === 'status')
                                            <x-ui.badge :color="$client->status->color()" class="capitalize">{{ $client->status->value }}</x-ui.badge>
                                        @elseif (is_array($value))
                                            <a href="{{ $value[1] }}" class="link" @if ($label === 'Website') target="_blank" rel="noopener" @endif>{{ $value[0] }}</a>
                                        @elseif ($value === null || $value === '')
                                            <span class="font-normal text-slate-400 dark:text-gray-500">—</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>

            <div class="space-y-6">
                <section class="card p-6">
                    @include('livewire.clients._details-head', ['title' => 'Address', 'icon' => 'globe'])
                    <dl class="space-y-5">
                        @foreach (['Street address' => $client->address, 'City / State / Postal / Country' => $addressLine] as $label => $value)
                            <div class="min-w-0">
                                <dt class="{{ $dt }}">{{ $label }}</dt>
                                <dd class="{{ $dd }}">@if ($value){{ $value }}@else<span class="font-normal text-slate-400 dark:text-gray-500">—</span>@endif</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="card p-6" data-test="client-notes">
                    @include('livewire.clients._details-head', ['title' => 'Internal notes', 'icon' => 'lock'])
                    @if ($client->notes)
                        <p class="whitespace-pre-line break-words text-sm text-slate-700 dark:text-gray-300">{{ $client->notes }}</p>
                    @else
                        <p class="text-sm text-slate-500 dark:text-gray-400">No notes.</p>
                    @endif
                    <p class="mt-4 flex items-center gap-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-gray-700 dark:text-gray-400"><x-icon name="eye-off" class="h-3.5 w-3.5" />Only your team sees this; never shown in the portal.</p>
                </section>
            </div>
            </div>
        </div>

        <div role="tabpanel" x-show="tab === 'projects'" x-cloak class="p-6">
            @include('livewire.clients.show-projects')
        </div>

        <div role="tabpanel" x-show="tab === 'invoices'" x-cloak class="p-6">
            @include('livewire.clients.show-invoices')
        </div>

        <div role="tabpanel" x-show="tab === 'payments'" x-cloak class="p-6">
            @php
                $paymentTypes = collect(\App\Enums\PaymentType::cases())->mapWithKeys(fn ($t) => [$t->value => ucfirst(str_replace('_', ' ', $t->value))])->all();
                $paymentDates = ['label' => 'Paid', 'id' => 'payment', 'method' => 'setPaymentPeriod', 'fromModel' => 'paymentFrom', 'toModel' => 'paymentTo', 'from' => $paymentFrom, 'to' => $paymentTo, 'period' => $paymentPeriod];
                $paymentActive = isset($paymentTypes[$paymentType]) + ($paymentFrom !== '' || $paymentTo !== '');
            @endphp
            @if ($paymentStats)
                @php
                    $pFmt = fn ($c) => $c === null ? '—' : $sm($c, $paymentStats['currency']);
                    $pSums = fn ($key) => collect($paymentsByCurrency)->map(fn ($r, $cur) => $sm($r[$key], $cur))->implode(' + ');
                    $pForeign = $isForeign($paymentsByCurrency);
                @endphp
                <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="client-payment-stats">
                    <x-ui.stat compact label="Total received" :value="$pFmt($paymentStats['total'])" color="green" icon="credit-card"
                        :hint="($pForeign ? $pSums('total').' · ' : '').'In '.$localCode.($paymentStats['missing_rate'] ? ' · '.$paymentStats['missing_rate'].' without a rate' : '')" />
                    <x-ui.stat compact label="Payments" :value="$paymentStats['count']" color="blue" icon="document" :hint="$payments->isNotEmpty() ? 'Latest '.$payments->first()->paid_on?->format('M j, Y') : null" />
                    <x-ui.stat compact label="Average payment" :value="$pFmt($paymentStats['average'])" color="purple" icon="currency" :hint="($pForeign ? $pSums('average').' · ' : '').'In '.$localCode" />
                    @if ($paymentStats['by_type'])
                        <x-ui.stat compact label="By type" color="yellow"
                            :lines="collect($paymentStats['by_type'])->take(3)->map(fn ($c, $t) => ['label' => $paymentTypes[$t] ?? $t, 'value' => $pFmt($c)])->values()->all()" />
                    @else
                        <x-ui.stat compact label="By type" value="—" color="yellow" icon="tag" hint="No payments in view" />
                    @endif
                </div>
            @endif
            <div class="mb-4 flex justify-end gap-2">
                <x-ui.filter-button for="client-payments" :active="$paymentActive" />
                @can('manage-money')
                    <a href="{{ route('payments.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary" data-test="client-add-payment"><x-nav.icon name="plus" class="h-5 w-5" /> Record payment</a>
                @endcan
            </div>
            <x-ui.filter-panel for="client-payments">
                <div class="sm:w-60">
                    <label for="paymentType" class="sr-only">Type</label>
                    <select wire:model="paymentType" id="paymentType" class="select">
                        @foreach (['' => 'All types'] + $paymentTypes as $value => $text)
                            <option value="{{ $value }}">{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
                @include('livewire.clients._date-filter', $paymentDates)
                <x-slot:chips>
                    @isset($paymentTypes[$paymentType])<x-ui.filter-chip :label="'Type: '.$paymentTypes[$paymentType]" wire:click="$set('paymentType', '')" />@endisset
                    @include('livewire.clients._date-filter', $paymentDates + ['chip' => true])
                </x-slot:chips>
            </x-ui.filter-panel>
            @if ($payments->isEmpty() && $paymentActive)
                <x-ui.empty-state title="No matches" message="No payments match your filters." name="credit-card" />
            @elseif ($payments->isEmpty())
                <x-ui.empty-state title="No payments yet" message="Payments received from this client will appear here." name="credit-card" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                        <thead><tr><th class="table-th">Date</th><th class="table-th">Amount</th><th class="table-th">Type</th><th class="table-th">Project</th><th class="table-th">Method</th><th class="table-th">Documents</th><th class="table-th"></th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                            @foreach ($payments as $payment)
                                <tr wire:key="payment-{{ $payment->id }}">
                                    <td class="table-td">{{ $payment->paid_on?->format('M j, Y') }}</td>
                                    <td class="table-td tabular-nums font-medium">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}
                                        @if ($payment->hasLocal() && strtoupper($payment->currency) !== $payment->local_currency)
                                            <div class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ \App\Support\Money::format($payment->local_amount_cents, $payment->local_currency) }} @ {{ rtrim(rtrim($payment->exchange_rate, '0'), '.') }}</div>
                                        @endif
                                    </td>
                                    <td class="table-td">{{ str_replace('_', ' ', ucfirst($payment->type->value)) }}</td>
                                    <td class="table-td">{{ $payment->project?->name ?? '—' }}</td>
                                    <td class="table-td">{{ $payment->method ?: '—' }}</td>
                                    <td class="table-td">
                                        @forelse ($payment->documents as $doc)
                                            <a href="{{ route('payment-documents.download', $doc) }}" class="link block text-xs">{{ $doc->original_name }}</a>
                                        @empty — @endforelse
                                    </td>
                                    @include('livewire.clients._row-actions', ['edit' => route('payments.edit', $payment), 'method' => 'deletePayment', 'id' => $payment->id, 'title' => 'Delete payment', 'text' => 'Delete this payment of '.\App\Support\Money::format($payment->amount_cents, $payment->currency).'? The client balance will change.'])
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($statement)
            <div role="tabpanel" x-show="tab === 'statement'" x-cloak class="p-6">@include('livewire.clients.show-statement')</div>
            <div role="tabpanel" x-show="tab === 'subscriptions'" x-cloak class="p-6">@include('livewire.clients.show-subscriptions')</div>
        @endif
        <div role="tabpanel" x-show="tab === 'access'" x-cloak class="p-6">@include('livewire.clients.show-access')</div>
    </div>

    <x-clients.delete-modal />
    <x-ui.confirm-modal />
</div>
