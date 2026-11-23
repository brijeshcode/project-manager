<div x-data="{ del: null }">
    <x-ui.page-header title="Clients" subtitle="Manage your clients and their information">
        <x-slot name="actions">
            <x-ui.filter-button :active="(int) ($status !== 'active')" />
            <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">
                <x-nav.icon name="plus" class="h-5 w-5" /> Add Client
            </a>
        </x-slot>
    </x-ui.page-header>

    <x-ui.filter-panel>
        <x-slot:bar>
            <label for="search" class="sr-only">Search Clients</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by name, code, contact or email..." class="input" />
        </x-slot:bar>
        <x-slot:chips>
            @if ($status !== 'active')<x-ui.filter-chip :label="'Status: '.ucfirst($status)" wire:click="$set('status', 'active')" />@endif
        </x-slot:chips>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="status" class="label">Status</label>
                <select wire:model="status" id="status" class="select">
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <div>
                <label for="per-page" class="label">Per Page</label>
                <select wire:model="perPage" id="per-page" class="select">
                    @foreach (\App\Support\Settings::PAGE_SIZES as $n)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-ui.filter-panel>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3" data-test="client-stats">
        <x-ui.stat label="Income (received)" icon="currency" color="green" :value="\App\Support\Money::format($stats['income'], $localCode)" />
        <x-ui.stat label="Balance" icon="currency" :color="$stats['balance'] < 0 ? 'yellow' : 'blue'" :value="\App\Support\Money::format($stats['balance'], $localCode)" :hint="$stats['missing'] ? 'Excludes rows without a local amount' : null" />
        <x-ui.stat label="Active subscriptions" icon="calendar" color="purple" :value="\App\Support\Money::format($stats['subs'], $localCode)" />
    </div>

    @if ($clients->isEmpty())
        @if ($search !== '' || $status !== 'active')
            <x-ui.empty-state title="No matches" message="Try adjusting your search criteria.">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state title="No clients yet" message="Add a client, then create projects and invoices under them.">
                <x-slot name="action">
                    <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">Add your first client</a>
                </x-slot>
            </x-ui.empty-state>
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Name</th>
                        <th class="table-th">Contact</th>
                        <th class="table-th">Subscriptions</th>
                        <th class="table-th">Balance</th>
                        <th class="table-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($clients as $client)
                        @php
                            $t = $totals[$client->id];
                            $balance = $t['received'] - $t['billed'];
                            $lt = $localTotals[$client->id];
                            $localBalance = $lt['missing'] > 0 ? null : $lt['received'] - $lt['billed'];
                            $sub = $subs[$client->id] ?? null;
                            $foreign = strtoupper($client->currency) !== $localCode;
                            $cls = $balance < 0 ? 'text-red-600 dark:text-red-400' : ($balance > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400');
                            $lbl = $balance < 0 ? 'Client owes' : ($balance > 0 ? 'Advance paid' : 'Settled');
                        @endphp
                        <tr wire:key="client-{{ $client->id }}" class="transition hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full {{ $client->status->value === 'active' ? 'bg-green-500' : 'bg-gray-400' }}" title="{{ ucfirst($client->status->value) }}"></span>
                                    <a href="{{ route('clients.show', $client) }}" wire:navigate class="text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $client->name }}</a>
                                    @if ($client->is_internal)<x-ui.badge color="slate" data-test="internal-badge">Internal</x-ui.badge>@endif
                                    @if ($client->portal_blocked_at)<x-ui.badge color="rose" data-test="portal-blocked">Portal blocked</x-ui.badge>@endif
                                </div>
                                <div class="text-xs capitalize text-gray-500 dark:text-gray-400" data-test="client-type">{{ $client->type->value }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap" data-test="client-contact">
                                @foreach ([$client->contact_name, $client->contact_email, $client->contact_phone] as $c)
                                    @if ($c)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $c }}</div>@endif
                                @endforeach
                            </td>
                            <td class="table-td whitespace-nowrap" data-test="client-subs">
                                @if ($sub)
                                    <div class="text-gray-900 dark:text-white">{{ \App\Support\Money::format($sub['amount'], $client->currency) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $sub['count'] }} active @if ($foreign) &bull; {{ \App\Support\Money::format($sub['local'], $localCode) }}@endif</div>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="table-td whitespace-nowrap" data-test="client-balance">
                                <div class="{{ $cls }}">{{ \App\Support\Money::format(abs($balance), $client->currency) }}</div>
                                <div class="text-xs {{ $cls }}">{{ $lbl }}@if ($foreign && $localBalance !== null) &bull; {{ \App\Support\Money::format(abs($localBalance), $localCode) }}@endif</div>
                            </td>
                            <td class="table-td whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('clients.show', $client) }}" wire:navigate title="View Client" aria-label="View {{ $client->name }}" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300"><x-nav.icon name="eye" class="h-4 w-4" /></a>
                                    <a href="{{ route('projects.create', ['client' => $client->id]) }}" wire:navigate title="Add Project" aria-label="Add project for {{ $client->name }}" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300"><x-nav.icon name="folder-plus" class="h-4 w-4" /></a>
                                    <a href="{{ route('clients.edit', $client) }}" wire:navigate title="Edit Client" aria-label="Edit {{ $client->name }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"><x-nav.icon name="pencil" class="h-4 w-4" /></a>
                                    <button type="button" title="Delete Client" aria-label="Delete {{ $client->name }}" x-on:click="del = { id: {{ $client->id }}, name: @js($client->name) }" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"><x-nav.icon name="trash" class="h-4 w-4" /></button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">
                <p class="mb-2 text-sm text-gray-700 dark:text-gray-300">Showing <span class="font-medium">{{ $clients->firstItem() }}</span> to <span class="font-medium">{{ $clients->lastItem() }}</span> of <span class="font-medium">{{ $clients->total() }}</span> results</p>
                {{ $clients->links() }}
            </x-slot>
        </x-ui.table-card>
    @endif

    <x-clients.delete-modal />
</div>
