<div>
    <x-ui.page-header title="Currencies" :subtitle="'Every currency the system can use. Local currency: '.$local.'. Active currencies appear in the payment, invoice and project forms.'" />

    <form wire:submit="add" class="card mb-6 grid gap-4 sm:grid-cols-4">
        <div>
            <label for="code" class="label">Code</label>
            <input wire:model="code" id="code" type="text" maxlength="3" placeholder="USD" class="input uppercase" />
            @error('code') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label for="name" class="label">Name</label>
            <input wire:model="name" id="name" type="text" maxlength="100" placeholder="US Dollar" class="input" />
            @error('name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-end"><button type="submit" class="btn btn-primary">Add currency</button></div>
    </form>

    <x-ui.table-card>
        <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
            <thead>
                <tr><th class="table-th">Code</th><th class="table-th">Name</th><th class="table-th">Rate to {{ $local }}</th><th class="table-th">Status</th><th class="table-th">In use</th><th class="table-th"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                @foreach ($currencies as $c)
                    <tr wire:key="cur-{{ $c->id }}">
                        <td class="table-td font-medium">{{ $c->code }}@if ($c->is_local) <x-ui.badge color="blue">local</x-ui.badge>@endif</td>
                        <td class="table-td">
                            <form wire:submit="rename({{ $c->id }})" class="flex items-center gap-1">
                                <label for="name-{{ $c->id }}" class="sr-only">Name of {{ $c->code }}</label>
                                <input wire:model="names.{{ $c->id }}" id="name-{{ $c->id }}" type="text" maxlength="100" class="input py-1 text-sm" />
                                <button type="submit" class="btn btn-secondary py-1 text-xs">Save</button>
                            </form>
                            @error("names.{$c->id}") <p class="field-error">{{ $message }}</p> @enderror
                        </td>
                        <td class="table-td">
                            @if ($c->is_local)
                                1
                            @else
                                <form wire:submit="setRate({{ $c->id }})" class="flex items-center gap-1">
                                    <label for="rate-{{ $c->id }}" class="sr-only">Rate for {{ $c->code }}</label>
                                    <input wire:model="rate.{{ $c->id }}" id="rate-{{ $c->id }}" type="text" inputmode="decimal" placeholder="{{ $c->active_rate ? rtrim(rtrim($c->active_rate, '0'), '.') : 'No rate' }}" class="input w-28 py-1 text-sm" />
                                    <button type="submit" class="btn btn-secondary py-1 text-xs">Set rate</button>
                                </form>
                                @error("rate.{$c->id}") <p class="field-error">{{ $message }}</p> @enderror
                            @endif
                        </td>
                        <td class="table-td"><x-ui.badge :color="$c->is_active ? 'green' : 'slate'">{{ $c->is_active ? 'active' : 'inactive' }}</x-ui.badge></td>
                        <td class="table-td tabular-nums">{{ $c->used }}</td>
                        <td class="table-td whitespace-nowrap text-right">
                            @unless ($c->is_local)
                                <button type="button" wire:click="toggle({{ $c->id }})" class="text-sm font-medium link">{{ $c->is_active ? 'Deactivate' : 'Activate' }}</button>
                                <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Delete {{ $c->code }}?" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400">Delete</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.table-card>
</div>
