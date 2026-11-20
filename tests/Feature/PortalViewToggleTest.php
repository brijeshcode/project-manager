<?php

use App\Enums\PaymentType;
use App\Livewire\Portal\Invoices;
use App\Livewire\Portal\Payments;
use App\Livewire\Portal\Projects;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->client = Client::create(['name' => 'Mine', 'currency' => 'USD', 'portal_features' => array_keys(Client::PORTAL_FEATURES)]);
    $this->other = Client::create(['name' => 'Theirs', 'currency' => 'USD']);
    $this->user = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');
    foreach ([[$this->client, 'MINE'], [$this->other, 'THEIRS']] as [$c, $tag]) {
        Project::create(['client_id' => $c->id, 'name' => "Proj $tag", 'status' => 'active']);
        Invoice::create(['number' => "INV-$tag", 'client_id' => $c->id, 'status' => 'sent', 'issued_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 100, 'tax_cents' => 0, 'total_cents' => 100]);
        Payment::create(['client_id' => $c->id, 'amount_cents' => 4321, 'paid_on' => today(), 'type' => PaymentType::Project, 'method' => "card-$tag", 'notes' => "secret-$tag"]);
    }
});

dataset('pages', [
    'projects' => [Projects::class, 'portal-projects-list', 'portal-project-card', 'Proj MINE', 'Proj THEIRS', 'cards'],
    'invoices' => [Invoices::class, 'portal-invoices-list', 'portal-invoice-card', 'INV-MINE', 'INV-THEIRS', 'list'],
    'payments' => [Payments::class, 'portal-payments-list', 'portal-payment-card', 'card-MINE', 'card-THEIRS', 'list'],
]);

it('switches between list and card views and keeps other clients out', function (string $cls, string $list, string $card, string $mine, string $theirs, string $default) {
    $page = fn (array $q) => Livewire::withQueryParams($q)->actingAs($this->user)->test($cls);

    $page(['view' => 'list'])->assertSeeHtml('data-test="'.$list.'"')->assertDontSeeHtml('data-test="'.$card.'"')->assertSee($mine)->assertDontSee($theirs)->assertDontSee('secret-MINE');
    $page(['view' => 'cards'])->assertSeeHtml('data-test="'.$card.'"')->assertDontSeeHtml('data-test="'.$list.'"')->assertSee($mine)->assertDontSee($theirs)->assertDontSee('secret-MINE');
    expect($page(['view' => 'bogus'])->get('view'))->toBe($default);
})->with('pages');
