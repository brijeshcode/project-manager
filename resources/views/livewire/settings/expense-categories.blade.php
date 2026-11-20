<div x-data="{ ask: null }">
    <x-ui.page-header title="Expense categories" subtitle="Every expense can have one category. Drag rows to reorder.">
        <x-slot:actions>
            @if ($editing === null)
                <button type="button" wire:click="create" class="btn btn-primary gap-1" data-test="new"><x-icon name="plus" /> New category</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing !== null)
        @include('livewire.settings._taxonomy-form', [
            'noun' => 'category', 'max' => 50, 'placeholder' => 'e.g. Hosting',
            'current' => $editing ? $categories->firstWhere('id', $editing) : null,
            'hint' => 'Renaming or recolouring updates the category on every expense.',
            'extra' => 'livewire.settings._expense-category-image',
        ])
    @endif

    @if ($categories->isEmpty())
        @if ($editing === null)
            <x-ui.empty-state name="tag" title="No expense categories yet" message="Add categories to group expenses.">
                <x-slot:action>
                    <button type="button" wire:click="create" class="btn btn-primary">New category</button>
                </x-slot:action>
            </x-ui.empty-state>
        @endif
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th w-10"><span class="sr-only">Order</span></th><th class="table-th">Category</th><th class="table-th">Expenses</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700" x-data x-sortable data-sort-method="reorder" data-test="categories">
                    @foreach ($categories as $c)
                        <tr wire:key="cat-{{ $c->id }}" data-id="{{ $c->id }}" @class($editing === $c->id ? 'bg-brand-50/50 dark:bg-gray-700/30' : 'bg-white dark:bg-transparent') data-test="category-row">
                            <td class="table-td w-10">
                                <span class="drag-handle cursor-grab text-slate-300 hover:text-slate-500 dark:text-gray-600 dark:hover:text-gray-400" title="Drag to reorder" aria-label="Drag to reorder"><x-icon name="grip-vertical" /></span>
                            </td>
                            <td class="table-td"><x-ui.expense-category :category="$c" /></td>
                            <td class="table-td whitespace-nowrap">{{ $c->expenses_count }} {{ Str::plural('expense', $c->expenses_count) }}</td>
                            <td class="table-td whitespace-nowrap text-right">
                                <button type="button" wire:click="edit({{ $c->id }})" class="text-sm font-medium link" aria-label="Edit {{ $c->name }}" data-test="edit">Edit</button>
                                <button type="button" x-on:click="ask = { method: 'delete', id: {{ $c->id }}, title: @js('Delete '.$c->name.'?'), text: @js($c->expenses_count ? 'The '.$c->expenses_count.' '.Str::plural('expense', $c->expenses_count).' using it are left without a category. This cannot be undone.' : 'No expenses use it. This cannot be undone.') }" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400" aria-label="Delete {{ $c->name }}" data-test="delete">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.confirm-modal />
</div>
