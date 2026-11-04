<div>
    <x-ui.page-header title="System" subtitle="Defaults for everyone. Each person can still pick their own sidebar layout from the sidebar." />

    <form wire:submit="save" class="card max-w-xl space-y-5" data-test="system-form">
        <div>
            <label for="page-size" class="label">Default page size</label>
            <select id="page-size" wire:model="pageSize" class="select" data-test="page-size">
                @foreach (\App\Support\Settings::PAGE_SIZES as $n)<option value="{{ $n }}">{{ $n }} rows per page</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Applies to every paginated list: clients, projects, invoices, payments and the client portal.</p>
            @error('pageSize') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="sidebar-layout" class="label">Default sidebar layout</label>
            <select id="sidebar-layout" wire:model="sidebarLayout" class="select" data-test="sidebar-layout">
                @foreach (\App\Support\Settings::SIDEBARS as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Used until a person chooses a layout of their own in this browser.</p>
            @error('sidebarLayout') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary" data-test="save">Save</button>
    </form>
</div>
