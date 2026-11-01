<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Livewire\Concerns\DeletesClient;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Client;
use App\Services\ClientAccount;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use DeletesClient, WithListSearch;

    private const PER_PAGE = [15, 25, 50, 100];

    #[Url]
    public int|string $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
    }

    public function updatedPerPage(): void
    {
        if (! in_array((int) $this->perPage, self::PER_PAGE, true)) {
            $this->perPage = 15;
        }
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function delete(int $clientId): void
    {
        if ($this->deleteClient($clientId)) {
            $this->dispatch('toast', type: 'success', message: 'Client deleted.');
        }
    }

    public function render()
    {
        $like = $this->likeTerm();
        $perPage = in_array((int) $this->perPage, self::PER_PAGE, true) ? (int) $this->perPage : 15;

        $clients = $this->fitPage(
            Client::orderBy('name')
                ->when(ClientStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s->value))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("client_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_email LIKE ? ESCAPE '!'", [$like]))),
            $perPage
        );

        return view('livewire.clients.index', [
            'clients' => $clients,
            'totals' => app(ClientAccount::class)->totals($clients->pluck('id')->all()),
            'perPage' => $perPage,
        ]);
    }
}
