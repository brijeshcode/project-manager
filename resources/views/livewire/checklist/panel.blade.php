<div>
    <ul>
        @foreach($items as $item)
            <li>
                <input type="checkbox"
                       wire:click="toggleItem({{ $item->id }})"
                       @checked($item->is_done)>
                {{ $item->title }}
            </li>
        @endforeach
    </ul>

    <form wire:submit="addItem">
        <input type="text" wire:model="newTitle" placeholder="Add checklist item">
        <button type="submit">Add</button>
    </form>
</div>
