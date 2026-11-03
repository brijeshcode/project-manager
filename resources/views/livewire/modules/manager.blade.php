<div>
    <ul>
        @foreach($modules as $module)
            <li>{{ $module->name }}</li>
        @endforeach
    </ul>

    <form wire:submit="createModule">
        <input wire:model="newName" type="text" placeholder="Module name" />
        <x-ui.markdown-editor model="newDescription" :rows="3" :uploads="false" placeholder="Description (optional, Markdown)…" />
        <button type="submit">Add</button>
    </form>
</div>
