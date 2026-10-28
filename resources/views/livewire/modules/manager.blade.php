<div>
    <ul>
        @foreach($modules as $module)
            <li>{{ $module->name }}</li>
        @endforeach
    </ul>

    <form wire:submit="createModule">
        <input wire:model="newName" type="text" placeholder="Module name" />
        <button type="submit">Add</button>
    </form>
</div>
