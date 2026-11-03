<?php

use App\Livewire\Invoices\Edit;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create()->assignRole('super_admin'));
});

function ieOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function ieClient(): Client
{
    return Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

function ieLine(Client $c, int $cents, string $desc = 'Work'): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => $desc, 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function ieInvoice(Client $c, array $lineIds, array $custom = [], float $tax = 0): Invoice
{
    return app(InvoiceService::class)->create($c, $lineIds, $custom, $tax, null, '83.5');
}

it('edits header, drops a line, adds a pending line and a custom line, and recomputes totals', function () {
    $c = ieClient();
    $a = ieLine($c, 10000, 'Alpha');
    $b = ieLine($c, 20000, 'Beta');
    $inv = ieInvoice($c, [$a->id]);
    $number = $inv->number;

    $svc = app(InvoiceService::class);
    $svc->update($inv, [$b->id], [['description' => 'Extra', 'quantity' => '2', 'unit_price' => '50.00']], [
        'tax_percent' => 10, 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'terms' => 'net-30', 'bank_account_id' => null, 'exchange_rate' => '90',
    ]);

    $inv->refresh();
    expect($inv->number)->toBe($number)
        ->and($inv->subtotal_cents)->toBe(30000)
        ->and($inv->tax_cents)->toBe(3000)
        ->and($inv->total_cents)->toBe(33000)
        ->and($inv->issued_on->toDateString())->toBe('2026-10-01')
        ->and($inv->due_on->toDateString())->toBe('2026-10-31')
        ->and((string) $inv->exchange_rate)->toBe('90.000000')
        ->and(count($inv->lines_snapshot))->toBe(2);
    expect($a->fresh()->invoice_id)->toBeNull();      // dropped line is pending again
    expect($b->fresh()->invoice_id)->toBe($inv->id);  // pending line claimed
});

it('rewrites a manual line in place instead of duplicating it', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [], [['description' => 'Setup', 'quantity' => '1', 'unit_price' => '100']]);
    $line = BillableLine::where('invoice_id', $inv->id)->first();
    $h = ['tax_percent' => 0, 'issued_on' => $inv->issued_on->toDateString(), 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];
    app(InvoiceService::class)->update($inv, [$line->id], [], $h, [$line->id => ['description' => 'Setup v2', 'quantity' => '2', 'unit_price' => '75']]);

    expect(BillableLine::where('invoice_id', $inv->id)->count())->toBe(1)
        ->and($line->fresh()->description)->toBe('Setup v2')
        ->and($inv->fresh()->total_cents)->toBe(15000)
        ->and($inv->fresh()->lines_snapshot[0]['description'])->toBe('Setup v2');
});

it('refuses to edit a cancelled invoice or leave it with no lines', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $svc = app(InvoiceService::class);
    $h = ['tax_percent' => 0, 'issued_on' => '2026-10-01', 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];

    expect(fn () => $svc->update($inv, [], [], $h))->toThrow(\Illuminate\Validation\ValidationException::class);

    $svc->void($inv);
    expect(fn () => $svc->update($inv->fresh(), [], [['description' => 'x', 'quantity' => '1', 'unit_price' => '1']], $h))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects another client\'s pending line on edit', function () {
    $c = ieClient();
    $other = Client::create(['name' => 'Other', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $foreign = ieLine($other, 500);
    $h = ['tax_percent' => 0, 'issued_on' => '2026-10-01', 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];

    expect(fn () => app(InvoiceService::class)->update($inv, [$foreign->id], [], $h))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($foreign->fresh()->invoice_id)->toBeNull();
});

it('deletes an invoice, frees its lines, keeps payments and updates the client balance', function () {
    $c = ieClient();
    $line = ieLine($c, 10000);
    $inv = ieInvoice($c, [$line->id]);
    $p = Payment::create(['client_id' => $c->id, 'invoice_id' => $inv->id, 'amount_cents' => 4000, 'paid_on' => today(), 'type' => 'project']);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-6000);

    app(InvoiceService::class)->delete($inv);

    expect(Invoice::count())->toBe(0)
        ->and($line->fresh()->invoice_id)->toBeNull()
        ->and($p->fresh()->invoice_id)->toBeNull()
        ->and(app(ClientAccount::class)->balanceCents($c))->toBe(4000);
});

it('edit page loads, saves and redirects; delete from the show page redirects to the list', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $owner = ieOwner();

    Livewire::actingAs($owner)->test(Edit::class, ['invoice' => $inv])
        ->assertSet('taxPercent', '0.00')
        ->set('taxPercent', '5')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('invoices.show', $inv));
    expect($inv->fresh()->tax_cents)->toBe(500);

    Livewire::actingAs($owner)->test(Show::class, ['invoice' => $inv])->call('delete')->assertRedirect(route('invoices.index'));
    expect(Invoice::count())->toBe(0);
});

it('shows stats that exclude cancelled invoices and are in local currency', function () {
    $c = ieClient();
    ieInvoice($c, [ieLine($c, 10000)->id]);          // 100.00 USD @ 83.5 = 8,350.00 INR
    $gone = ieInvoice($c, [ieLine($c, 90000)->id]);
    app(InvoiceService::class)->void($gone);

    Livewire::actingAs(ieOwner())->test(Index::class)
        ->assertSee('Total invoiced')->assertSee('8,350.00')->assertDontSee('83,850.00')->assertSee('Cancelled');
});

it('is owner only', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Edit::class, ['invoice' => $inv])->assertForbidden();
});
