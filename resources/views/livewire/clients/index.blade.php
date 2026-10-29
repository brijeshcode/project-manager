<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Clients</h1>
        <a href="{{ route('clients.create') }}" wire:navigate
           class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">
            New Client
        </a>
    </div>

    @if ($clients->isEmpty())
        <p class="text-gray-500">No clients yet.</p>
    @else
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Currency</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($clients as $client)
                    <tr>
                        <td class="px-4 py-2 text-sm text-gray-900">{{ $client->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $client->currency }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('clients.edit', $client) }}" wire:navigate
                               class="text-indigo-600 hover:underline text-sm mr-3">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
