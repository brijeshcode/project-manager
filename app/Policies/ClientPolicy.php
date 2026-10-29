<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    // super_admin passes all checks via Gate::before; everyone else is denied.

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Client $client): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Client $client): bool
    {
        return false;
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
