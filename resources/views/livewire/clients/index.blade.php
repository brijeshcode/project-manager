<div>
    <x-ui.page-header title="Clients" subtitle="The organizations you work with and bill.">
        <x-slot name="actions">
            <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">
                <span class="text-base leading-none">+</span> New client
            </a>
        </x-slot>
    </x-ui.page-header>

    <x-ui.filter-card>
        <div class="sm:col-span-2">
            <label for="search" class="sr-only">Search clients</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search clients by name..." class="input" />
        </div>
    </x-ui.filter-card>

    @if ($clients->isEmpty())
        @if ($search !== '')
            <x-ui.empty-state title="No matches" message="No clients match your search.">
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
                        <th class="table-th">Currency</th>
                        <th class="table-th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($clients as $client)
                        <tr wire:key="client-{{ $client->id }}" class="transition hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-blue-900/30 dark:text-blue-300">
                                        {{ mb_substr($client->name, 0, 1) }}
                                    </span>
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $client->name }}</span>
                                </div>
                            </td>
                            <td class="table-td"><x-ui.badge color="slate">{{ $client->currency }}</x-ui.badge></td>
                            <td class="table-td text-right">
                                <a href="{{ route('clients.edit', $client) }}" wire:navigate class="link font-medium">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $clients->links() }}</x-slot>
        </x-ui.table-card>
    @endif
</div>
