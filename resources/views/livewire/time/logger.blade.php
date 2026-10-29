<div>
    <form wire:submit="log">
        <input type="number" step="0.25" wire:model="hours" placeholder="Hours" min="0.01">
        <input type="date" wire:model="worked_on">
        <button type="submit">Log Time</button>
    </form>

    <ul>
        @foreach($entries as $entry)
            <li>{{ $entry->worked_on->toDateString() }} — {{ $entry->hours }}h</li>
        @endforeach
    </ul>
</div>
