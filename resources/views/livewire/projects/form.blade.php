<div>
    <form wire:submit="save">
        <select wire:model="client_id">
            <option value="">Select Client</option>
            @foreach($clients as $client)
                <option value="{{ $client->id }}">{{ $client->name }}</option>
            @endforeach
        </select>
        <input type="text" wire:model="name" placeholder="Project name">
        <input type="number" wire:model="buffer_percent" placeholder="Buffer %">
        <input type="date" wire:model="start_date">
        <input type="text" wire:model="status" placeholder="Status">
        <button type="submit">Save</button>
    </form>
</div>
