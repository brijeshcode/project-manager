@php
    $balance = $received - $billed;
    $fmt = fn (int $c) => \App\Support\Money::format($c, $client->currency);
    $addressLine = collect([$client->city, $client->state, $client->postal_code, $client->country])->filter()->implode(', ');
    $sections = [
        'Basic Information' => [
            'Client Type' => ucfirst($client->type->value), 'Client Code' => $client->client_code, 'Status' => ucfirst($client->status->value),
            'Billing Mode' => ucfirst($client->billing_mode->value), 'Currency' => $client->currency,
        ],
        'Contact Information' => [
            'Contact Person' => $client->contact_name, 'Email' => $client->contact_email, 'Phone' => $client->contact_phone,
            'Website' => $client->website, 'Address' => $client->address, 'City / State / Postal / Country' => $addressLine,
        ],
        'Billing' => [
            'Tax / VAT Number' => $client->tax_number, 'Payment Terms' => $client->payment_terms, 'Billing Address' => $client->billing_address,
        ],
    ];
@endphp
<div x-data="{ tab: 'details', del: null }" class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-4">
            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400">
                <x-nav.icon :name="$client->type->value === 'company' ? 'building' : 'user'" class="h-8 w-8" />
            </span>
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $client->name }}</h1>
                <div class="mt-1 flex items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $client->client_code }}</span>
                    <x-ui.badge :color="$client->status->color()" class="capitalize">{{ $client->status->value }}</x-ui.badge>
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

    {{-- Financial summary --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <x-ui.stat label="Total Billed" :value="$fmt($billed)" icon="currency" color="green" />
        <x-ui.stat label="Total Received" :value="$fmt($received)" icon="credit-card" color="blue" />
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

    {{-- Tabs --}}
    <div class="card">
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex gap-8 px-6" aria-label="Tabs">
                @foreach (['details' => 'Details', 'projects' => 'Projects', 'invoices' => 'Invoices'] as $key => $label)
                    <button type="button" x-on:click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:text-gray-300'"
                            class="whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors">{{ $label }}</button>
                @endforeach
            </nav>
        </div>

        <div x-show="tab === 'details'" class="space-y-8 p-6">
            @foreach ($sections as $title => $fields)
                <section>
                    <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">{{ $title }}</h3>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach (array_filter($fields, fn ($v) => $v !== null && $v !== '') as $label => $value)
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-gray-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endforeach
            @if ($client->notes)
                <section>
                    <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Internal Notes</h3>
                    <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $client->notes }}</p>
                </section>
            @endif
        </div>

        <div x-show="tab === 'projects'" x-cloak class="p-6">
            @if ($projects->isEmpty())
                <x-ui.empty-state title="No projects yet" message="Projects for this client will appear here.">
                    <x-slot name="action"><a href="{{ route('projects.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary">Add Project</a></x-slot>
                </x-ui.empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                        <thead><tr><th class="table-th">Project</th><th class="table-th">Status</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                            @foreach ($projects as $project)
                                <tr wire:key="project-{{ $project->id }}">
                                    <td class="table-td"><a href="{{ route('projects.show', $project) }}" wire:navigate class="link font-medium">{{ $project->name }}</a></td>
                                    <td class="table-td"><x-ui.badge :color="$project->status === 'active' ? 'green' : 'slate'">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div x-show="tab === 'invoices'" x-cloak class="p-6">
            @if ($invoices->isEmpty())
                <x-ui.empty-state title="No invoices yet" message="Invoices issued to this client will appear here." name="document" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                        <thead><tr><th class="table-th">Invoice</th><th class="table-th">Date</th><th class="table-th">Total</th><th class="table-th">Status</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                            @foreach ($invoices as $invoice)
                                <tr wire:key="invoice-{{ $invoice->id }}">
                                    <td class="table-td"><a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="link font-medium">{{ $invoice->number }}</a></td>
                                    <td class="table-td">{{ $invoice->issued_on?->format('M j, Y') }}</td>
                                    <td class="table-td tabular-nums">{{ \App\Support\Money::format($invoice->total_cents, $invoice->currency) }}</td>
                                    <td class="table-td"><x-ui.badge :color="['sent' => 'green', 'void' => 'rose', 'draft' => 'amber'][$invoice->status] ?? 'slate'">{{ ucfirst($invoice->status) }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <x-clients.delete-modal />
</div>
