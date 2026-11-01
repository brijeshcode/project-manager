<div x-data="{ del: null }">
    <x-ui.page-header title="Clients" subtitle="Manage your clients and their information">
        <x-slot name="actions">
            <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">
                <x-nav.icon name="plus" class="h-5 w-5" /> Add Client
            </a>
        </x-slot>
    </x-ui.page-header>

    <x-ui.filter-card>
        <div class="sm:col-span-2">
            <label for="search" class="label">Search Clients</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by name, code, contact or email..." class="input" />
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select wire:model.live="status" id="status" class="select">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
            </select>
        </div>
        <div>
            <label for="per-page" class="label">Per Page</label>
            <select wire:model.live="perPage" id="per-page" class="select">
                @foreach ([15, 25, 50, 100] as $n)
                    <option value="{{ $n }}">{{ $n }}</option>
                @endforeach
            </select>
        </div>
    </x-ui.filter-card>

    @if ($clients->isEmpty())
        @if ($search !== '' || $status !== '')
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
                        <th class="table-th">Client</th>
                        <th class="table-th">Contact</th>
                        <th class="table-th">Financial Summary</th>
                        <th class="table-th">Status</th>
                        <th class="table-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($clients as $client)
                        @php
                            $t = $totals[$client->id];
                            $balance = $t['received'] - $t['billed'];
                        @endphp
                        <tr wire:key="client-{{ $client->id }}" class="transition hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap">
                                <div class="flex items-center gap-4">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400">
                                        <x-nav.icon :name="$client->type->value === 'company' ? 'building' : 'user'" class="h-5 w-5" />
                                    </span>
                                    <div>
                                        <a href="{{ route('clients.show', $client) }}" wire:navigate class="text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $client->name }}</a>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $client->client_code }}@if ($client->contact_name) <span class="ml-2">&bull; {{ $client->contact_name }}</span>@endif</div>
                                    </div>
                                </div>
                            </td>
                            <td class="table-td whitespace-nowrap">
                                <div class="text-gray-900 dark:text-white">{{ $client->contact_email ?: 'No email' }}</div>
                                <div class="text-gray-500 dark:text-gray-400">{{ $client->contact_phone ?: 'No phone' }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap">
                                <div class="text-gray-900 dark:text-white">Billed: {{ \App\Support\Money::format($t['billed'], $client->currency) }}</div>
                                <div class="text-gray-500 dark:text-gray-400">Balance: {{ \App\Support\Money::format($balance, $client->currency) }}</div>
                            </td>
                            <td class="table-td whitespace-nowrap"><x-ui.badge :color="$client->status->color()" class="capitalize">{{ $client->status->value }}</x-ui.badge></td>
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
