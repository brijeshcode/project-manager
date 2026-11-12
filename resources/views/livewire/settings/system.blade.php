<div>
    <x-ui.page-header title="System" subtitle="Defaults for everyone. Each person can still pick their own sidebar layout from the sidebar." />

    <form wire:submit="save" class="space-y-8" data-test="system-form">
        <section class="grid gap-4 lg:grid-cols-3" data-test="section-lists">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">Lists</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">How many rows a list shows before it pages.</p>
            </div>
            <div class="card p-5 lg:col-span-2">
                <div class="max-w-xs">
                    <label for="page-size" class="label">Default page size</label>
                    <select id="page-size" wire:model="pageSize" class="select" data-test="page-size">
                        @foreach (\App\Support\Settings::PAGE_SIZES as $n)<option value="{{ $n }}">{{ $n }} rows per page</option>@endforeach
                    </select>
                    @error('pageSize') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">Applies to every paginated list: clients, projects, invoices, payments and the client portal.</p>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-3" data-test="section-numbers">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">Numbers</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">How amounts are grouped and shortened everywhere.</p>
            </div>
            <div class="card p-5 lg:col-span-2">
                <div class="max-w-sm">
                    <label for="number-format" class="label">Number format</label>
                    <select id="number-format" wire:model="numberFormat" class="select" data-test="number-format">
                        @foreach (\App\Support\Settings::NUMBER_FORMATS as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                    </select>
                    @error('numberFormat') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">Applies to every amount: invoices, payments, PDFs, dashboard and charts. Indian shortens 18,00,000 to 18L and 1,00,00,000 to 1Cr.</p>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-3" data-test="section-sidebar">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">Sidebar</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">The layout new visitors start with.</p>
            </div>
            <div class="card p-5 lg:col-span-2">
                <div class="max-w-xs">
                    <label for="sidebar-layout" class="label">Default sidebar layout</label>
                    <select id="sidebar-layout" wire:model="sidebarLayout" class="select" data-test="sidebar-layout">
                        @foreach (\App\Support\Settings::SIDEBARS as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                    </select>
                    @error('sidebarLayout') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">Used until a person chooses a layout of their own in this browser.</p>
            </div>
        </section>

        <div class="flex justify-end border-t border-slate-200 pt-4 dark:border-gray-700">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save" data-test="save">Save</button>
        </div>
    </form>
</div>
