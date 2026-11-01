<?php

namespace App\Livewire\Concerns;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

trait DeletesClient
{
    /** Hard-deletes an unused client; otherwise toasts what blocks it. True when deleted. */
    protected function deleteClient(int $clientId): bool
    {
        $client = Client::findOrFail($clientId);
        $this->authorize('delete', $client);

        $blockers = DB::transaction(function () use ($client) {
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $blockers = $locked->deleteBlockers();
            $blockers || $locked->delete();

            return $blockers;
        });

        if ($blockers) {
            $this->dispatch('toast', type: 'error', message: 'Cannot delete: this client still has '.implode(', ', $blockers).'. Set its status to archived instead.');
        }

        return ! $blockers;
    }
}
