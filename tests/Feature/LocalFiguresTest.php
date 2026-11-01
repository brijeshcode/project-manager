<?php

use App\Livewire\Clients\Show as ClientShow;
use App\Livewire\Projects\Economics;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ProjectEconomics;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
});

function lfocProj(string $cur = 'USD'): Project
{
    $c = Client::create(['name' => 'Acme '.$cur, 'currency' => $cur, 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'LfProj', 'status' => 'active', 'buffer_percent' => 0]);
    Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1]);

    return $p;
}

function lfocInv(Project $p, int $cents, ?string $rate): void
{
    $l = BillableLine::create(['client_id' => $p->client_id, 'project_id' => $p->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
    app(InvoiceService::class)->create($p->client, [$l->id])->forceFill(['status' => 'sent', 'exchange_rate' => $rate])->save();
}

function lfocPay(Project|Client $to, int $cents, ?int $local, ?string $rate = null): void
{
    $client = $to instanceof Project ? $to->client : $to;
    Payment::create(['client_id' => $client->id, 'project_id' => $to instanceof Project ? $to->id : null, 'amount_cents' => $cents,
        'paid_on' => today()->toDateString(), 'type' => 'project', 'exchange_rate' => $rate,
        'local_amount_cents' => $local, 'local_currency' => $local === null ? null : 'INR']);
}

it('economics returns client and local invoiced/received', function () {
    $p = lfocProj();
    lfocInv($p, 100000, '80');
    lfocInv($p, 5000, null);          // no frozen rate: skipped and counted
    lfocPay($p, 50000, 4_100_000, '82');
    lfocPay($p, 1000, null);              // no local: excluded and counted

    $e = ProjectEconomics::for($p);

    expect($e['invoiced_cents'])->toBe(105000)->and($e['invoiced_local'])->toBe(8_000_000)->and($e['invoiced_missing_rate'])->toBe(1)
        ->and($e['received_cents'])->toBe(51000)->and($e['received_local'])->toBe(4_100_000)->and($e['received_missing_rate'])->toBe(1);
});

it('economics page shows both numbers; cost and profit stay client currency', function () {
    $p = lfocProj();
    lfocInv($p, 100000, '80');
    lfocPay($p, 50000, 4_100_000, '82');

    Livewire::actingAs($this->owner)->test(Economics::class, ['project' => $p])->assertOk()
        ->assertSee('$1,000.00')->assertSee('₹80,000.00')->assertSee('$500.00')->assertSee('₹41,000.00');
});

it('project page shows the Money card to the owner only', function () {
    $p = lfocProj();
    lfocInv($p, 100000, '80');
    lfocPay($p, 50000, 4_100_000, '82');

    $this->actingAs($this->owner)->get(route('projects.show', $p))->assertOk()
        ->assertSee('Money')->assertSee('Invoiced')->assertSee('$1,000.00')->assertSee('₹80,000.00')->assertSee('₹41,000.00');

    $dev = User::factory()->create()->assignRole('developer');
    $p->members()->attach($dev->id, ['role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    $this->actingAs($dev)->get(route('projects.show', $p))->assertOk()->assertSee('LfProj')
        ->assertDontSee('Invoiced')->assertDontSee('$1,000.00')->assertDontSee('₹80,000.00')->assertDontSee('₹41,000.00');
});

it('a same-currency project shows each figure once, with no local line', function () {
    $p = lfocProj('INR');
    lfocInv($p, 100000, null);
    lfocPay($p, 50000, 50000, '1');

    $html = $this->actingAs($this->owner)->get(route('projects.show', $p))->assertOk()->getContent();
    expect(substr_count($html, '₹1,000.00'))->toBe(1)->and(substr_count($html, '₹500.00'))->toBe(1);
    $html = $this->get(route('projects.economics', $p))->assertOk()->getContent();
    expect(substr_count($html, '₹1,000.00'))->toBe(1)->and(substr_count($html, '₹500.00'))->toBe(2); // Received + Profit
});

it('client page shows local lines for billed, received and each payment', function () {
    $p = lfocProj();
    lfocInv($p, 20000, '80');
    lfocPay($p->client, 5000, 410_000, '82');

    Livewire::actingAs($this->owner)->test(ClientShow::class, ['client' => $p->client])->assertOk()
        ->assertSee('$200.00')->assertSee('₹16,000.00')->assertSee('$50.00')->assertSee('₹4,100.00')->assertSee('₹4,100.00 @ 82');
});

it('client page hides local lines for a same-currency client', function () {
    $p = lfocProj('INR');
    lfocInv($p, 20000, null);
    lfocPay($p->client, 5000, 5000, '1');

    $html = Livewire::actingAs($this->owner)->test(ClientShow::class, ['client' => $p->client])->assertOk()->html();
    // Billed appears in its card + the invoice row, received in its card + the payment row: nothing doubled.
    expect(substr_count($html, '₹200.00'))->toBe(2)->and(substr_count($html, '₹50.00'))->toBe(2)->and($html)->not->toContain(' @ 1');
});

it('a payment with no rate shows no local line, never zero', function () {
    $p = lfocProj();
    lfocPay($p->client, 5000, null);

    Livewire::actingAs($this->owner)->test(ClientShow::class, ['client' => $p->client])->assertOk()
        ->assertSee('$50.00')->assertDontSee('₹0.00')->assertDontSee('₹');
});

it('never shows a partial local total: a note with the count replaces it (economics, Money card, client page)', function () {
    $p = lfocProj();
    lfocInv($p, 100000, '80');
    lfocInv($p, 5000, null);              // unrated
    lfocPay($p, 50000, 4_100_000, '82');
    lfocPay($p, 1000, null);              // no local

    $pages = [
        $this->actingAs($this->owner)->get(route('projects.show', $p))->assertOk()->getContent(),
        $this->get(route('projects.economics', $p))->assertOk()->getContent(),
        Livewire::test(ClientShow::class, ['client' => $p->client])->assertOk()->html(),
    ];
    foreach ($pages as $i => $html) {
        // the client page's Payments tab legitimately lists the rated payment's own local amount
        $i < 2 && expect($html)->not->toContain('₹41,000.00');
        expect($html)->not->toContain('₹80,000.00')
            ->and($html)->toContain('Local total unavailable — 1 without an exchange rate');
    }
});

it('the full local figure shows once every invoice and payment is rated', function () {
    $p = lfocProj();
    lfocInv($p, 100000, '80');
    lfocInv($p, 5000, '80');
    lfocPay($p, 50000, 4_100_000, '82');
    lfocPay($p, 1000, 82_000, '82');

    $this->actingAs($this->owner)->get(route('projects.economics', $p))->assertOk()
        ->assertSee('₹84,000.00')->assertSee('₹41,820.00')->assertDontSee('Local total unavailable');
});

it('client Payments tab lists document counts and escaped links', function () {
    $p = lfocProj();
    lfocPay($p->client, 5000, 410_000, '82');
    $pay = Payment::firstOrFail();
    \App\Models\PaymentDocument::create(['payment_id' => $pay->id, 'path' => 'x/a.pdf', 'original_name' => '<script>alert(1)</script>.pdf', 'uploaded_by' => $this->owner->id]);
    \App\Models\PaymentDocument::create(['payment_id' => $pay->id, 'path' => 'x/b.pdf', 'original_name' => 'receipt.pdf', 'uploaded_by' => $this->owner->id]);

    $t = Livewire::actingAs($this->owner)->test(ClientShow::class, ['client' => $p->client])->assertOk();
    $doc = $pay->documents()->where('original_name', 'receipt.pdf')->first();
    $t->assertSee('Documents')->assertSee('receipt.pdf')->assertSee(route('payment-documents.download', $doc), false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false);
});
