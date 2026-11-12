<div>
    <x-ui.page-header title="Statement" subtitle="Your invoices and payments with the running balance." />

    <div class="card p-6">@include('livewire.clients.show-statement', ['portal' => true, 'client' => $client, 'statement' => $statement])</div>
</div>
