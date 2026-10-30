<div>
    <x-ui.page-header title="Clients" subtitle="The organizations you work with and bill.">
        <x-slot name="actions">
            <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">
                <span class="text-base leading-none">+</span> New client
            </a>
        </x-slot>
    </x-ui.page-header>

    @if ($clients->isEmpty())
        <x-ui.empty-state title="No clients yet" message="Add a client, then create projects and invoices under them.">
            <x-slot name="action">
                <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary">Add your first client</a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Currency</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($clients as $client)
                        <tr class="transition hover:bg-slate-50">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-700">
                                        {{ mb_substr($client->name, 0, 1) }}
                                    </span>
                                    <span class="text-sm font-semibold text-slate-900">{{ $client->name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <x-ui.badge color="slate">{{ $client->currency }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <a href="{{ route('clients.edit', $client) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:text-brand-700">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
