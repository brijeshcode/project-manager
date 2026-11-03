<?php

use App\Enums\PaymentType;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecurringCharge;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ivClient(array $o = []): Client
{
    return Client::create($o + ['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
}

function ivLine(Client $c, int $cents, array $o = []): BillableLine
{
    return BillableLine::create($o + ['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function ivBal(Client $c): int
{
    return app(ClientAccount::class)->balanceCents($c);
}

function ivSvc(): InvoiceService
{
    return app(InvoiceService::class);
}

function ivPay(Client $c, int $cents, ?int $invoiceId = null): Payment
{
    return Payment::create(['client_id' => $c->id, 'invoice_id' => $invoiceId, 'amount_cents' => $cents, 'paid_on' => '2026-11-01', 'type' => PaymentType::Project]);
}

it('creates an invoice with totals, attached lines and moves the balance', function () {
    $c = ivClient();
    $a = ivLine($c, 10000);
    $b = ivLine($c, 20000);
    $free = ivLine($c, 500);
    $inv = ivSvc()->create($c, [$a->id, $b->id], [], 10.0);
    expect($inv->subtotal_cents)->toBe(30000)
        ->and($inv->tax_cents)->toBe(3000)
        ->and($inv->total_cents)->toBe(33000)
        ->and($inv->status)->toBe('sent')
        ->and($inv->currency)->toBe('USD')
        ->and($inv->number)->toMatch('/^INV-\d{8}-01$/')
        ->and($inv->issued_on->toDateString())->toBe(now()->toDateString())
        ->and($inv->lines()->count())->toBe(2)
        ->and($a->fresh()->invoice_id)->toBe($inv->id)
        ->and($free->fresh()->invoice_id)->toBeNull()
        ->and(ivBal($c))->toBe(-33000);
});

it('numbers sequentially, never reuses voided numbers, and survives a forced collision', function () {
    $c = ivClient();
    ivSvc()->create($c, [ivLine($c, 100)->id]);
    $two = ivSvc()->create($c, [ivLine($c, 100)->id]);
    expect($two->number)->toEndWith('-02');
    ivSvc()->void($two);
    $three = ivSvc()->create($c, [ivLine($c, 100)->id]);
    expect($three->number)->toEndWith('-03');

    // a concurrent writer grabs the number we computed, just before our insert
    $fired = false;
    $creates = 0;
    Invoice::creating(function ($inv) use (&$fired, &$creates, $c) {
        $creates++;
        if ($fired) {
            return;
        }
        $fired = true;
        DB::table('invoices')->insert([
            'number' => $inv->number, 'client_id' => $c->id, 'status' => 'sent', 'issued_on' => now()->toDateString(),
            'currency_id' => \App\Models\Currency::forCode('USD')->id, 'subtotal_cents' => 0, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $four = ivSvc()->create($c, [ivLine($c, 100)->id]);
    expect($fired)->toBeTrue()
        ->and($creates)->toBe(2) // first insert collided, second attempt succeeded
        ->and($four->number)->toEndWith('-04')
        ->and(Invoice::pluck('number')->unique()->count())->toBe(Invoice::count());
});

it('rejects a pending line of another client and creates nothing', function () {
    $c = ivClient();
    $other = ivClient(['name' => 'Other']);
    $mine = ivLine($c, 100);
    $theirs = ivLine($other, 100);
    expect(fn () => ivSvc()->create($c, [$mine->id, $theirs->id]))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)
        ->and($mine->fresh()->invoice_id)->toBeNull()
        ->and($theirs->fresh()->invoice_id)->toBeNull();
});

it('rejects an already-invoiced line', function () {
    $c = ivClient();
    $l = ivLine($c, 100);
    ivSvc()->create($c, [$l->id]);
    expect(fn () => ivSvc()->create($c, [$l->id]))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(1);
});

it('requires at least one line', function () {
    $c = ivClient();
    expect(fn () => ivSvc()->create($c, []))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0);
});

it('adds custom lines as billable rows and supports custom-only invoices', function () {
    $c = ivClient();
    $inv = ivSvc()->create($c, [], [['description' => 'Hosting', 'quantity' => 1.5, 'unit_price' => '10.01']]);
    $line = $inv->lines()->first();
    expect($line->description)->toBe('Hosting')
        ->and($line->unit_price_cents)->toBe(1001)
        ->and($line->amount_cents)->toBe(1502) // 1.5 x 1001 = 1501.5 -> half-up
        ->and($line->client_id)->toBe($c->id)
        ->and($line->source_type)->toBeNull()
        ->and($inv->total_cents)->toBe(1502);
});

it('rejects invalid custom lines', function (array $custom) {
    $c = ivClient();
    expect(fn () => ivSvc()->create($c, [], [$custom]))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)->and(BillableLine::count())->toBe(0);
})->with([
    'empty description' => [['description' => ' ', 'quantity' => 1, 'unit_price' => 5]],
    'zero quantity' => [['description' => 'x', 'quantity' => 0, 'unit_price' => 5]],
    'negative price' => [['description' => 'x', 'quantity' => 1, 'unit_price' => -1]],
    'non numeric' => [['description' => 'x', 'quantity' => 'abc', 'unit_price' => 5]],
]);

it('rolls back custom lines when a later step fails', function () {
    $c = ivClient();
    expect(fn () => ivSvc()->create($c, [], [['description' => 'ok', 'quantity' => 1, 'unit_price' => 5]], 0.0, 999999))->toThrow(ModelNotFoundException::class);
    expect(Invoice::count())->toBe(0)->and(BillableLine::count())->toBe(0);
});

it('rounds tax half-up and validates the tax range', function () {
    $c = ivClient();
    expect(ivSvc()->create($c, [ivLine($c, 1005)->id], [], 10.0)->tax_cents)->toBe(101);
    expect(ivSvc()->create($c, [ivLine($c, 1005)->id], [], 0.0)->tax_cents)->toBe(0);
    expect(ivSvc()->create($c, [ivLine($c, 1005)->id], [], 100.0)->tax_cents)->toBe(1005);
    foreach ([100.01, -1.0] as $bad) {
        $l = ivLine($c, 100);
        expect(fn () => ivSvc()->create($c, [$l->id], [], $bad))->toThrow(ValidationException::class);
        expect($l->fresh()->invoice_id)->toBeNull();
    }
});

it('derives due date and terms from payment_terms', function () {
    $c = ivClient(['payment_terms' => 'net-30']);
    $inv = ivSvc()->create($c, [ivLine($c, 100)->id]);
    expect($inv->due_on->toDateString())->toBe(now()->addDays(30)->toDateString())->and($inv->terms)->toBe('net-30');
    $d = ivClient(['payment_terms' => 'Due on receipt']);
    $inv = ivSvc()->create($d, [ivLine($d, 100)->id]);
    expect($inv->due_on)->toBeNull()->and($inv->terms)->toBe('Due on receipt');
    $e = ivClient();
    expect(ivSvc()->create($e, [ivLine($e, 100)->id])->terms)->toBeNull();
});

it('voids: lines return to pending, balance restores, second void is a no-op', function () {
    $c = ivClient();
    $a = ivLine($c, 10000);
    $inv = ivSvc()->create($c, [$a->id], [['description' => 'Extra', 'quantity' => 1, 'unit_price' => 50]]);
    expect(ivBal($c))->toBe(-15000);
    ivSvc()->void($inv);
    expect($inv->fresh()->status)->toBe('void')
        ->and($a->fresh()->invoice_id)->toBeNull()
        ->and(BillableLine::pending()->count())->toBe(2)
        ->and(ivBal($c))->toBe(0);
    $l = ivLine($c, 100);
    BillableLine::where('id', $l->id)->update(['invoice_id' => $inv->id]); // would be reset only if void ran again
    ivSvc()->void($inv->fresh());
    expect($l->fresh()->invoice_id)->toBe($inv->id);
});

it('void returns a recurring-sourced line to pending without duplicating it', function () {
    $c = ivClient();
    $r = RecurringCharge::create(['client_id' => $c->id, 'description' => 'Retainer', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => '2026-11-01', 'active' => true]);
    $l = ivLine($c, 5000, ['recurring_charge_id' => $r->id, 'period' => '2026-11']);
    $inv = ivSvc()->create($c, [$l->id]);
    ivSvc()->void($inv);
    expect(BillableLine::count())->toBe(1)->and($l->fresh()->invoice_id)->toBeNull();
});

it('balance follows create, payment and void', function () {
    $c = ivClient();
    $inv = ivSvc()->create($c, [ivLine($c, 20000)->id]);
    ivPay($c, 30000, $inv->id);
    expect(ivBal($c))->toBe(10000);
    ivSvc()->void($inv);
    expect(ivBal($c))->toBe(30000)
        ->and(Payment::count())->toBe(1);
});

it('a draft invoice does not change the client balance until it is sent', function () {
    $c = ivClient();
    $inv = ivSvc()->create($c, [ivLine($c, 20000)->id], [], 0.0, null, null, null, true);
    expect($inv->status)->toBe('draft')->and($inv->emailed_at)->toBeNull()->and(ivBal($c))->toBe(0);
    expect(ivSvc()->markSent($inv))->toBeTrue();
    expect($inv->fresh()->status)->toBe('sent')->and(ivBal($c))->toBe(-20000);
    expect(ivSvc()->markSent($inv->fresh()))->toBeFalse();
});

it('snapshots the bank account encrypted and keeps it after edit or delete', function () {
    $c = ivClient();
    $b = BankAccount::create(['label' => 'Main', 'account_name' => 'Me Ltd', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc_swift' => 'HDFC0001', 'email' => 'me@x.com']);
    $inv = ivSvc()->create($c, [ivLine($c, 100)->id], [], 0.0, $b->id);
    $expected = ['account_name' => 'Me Ltd', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc_swift' => 'HDFC0001', 'email' => 'me@x.com'];
    expect($inv->fresh()->bank_snapshot)->toBe($expected);
    expect(DB::table('invoices')->value('bank_snapshot'))->not->toContain('1234567890');
    expect($inv->toArray())->not->toHaveKey('bank_snapshot');
    $b->update(['account_number' => '999', 'bank_name' => 'Other']);
    expect($inv->fresh()->bank_snapshot)->toBe($expected);
    $b->delete();
    $fresh = $inv->fresh();
    expect($fresh->bank_snapshot)->toBe($expected)->and($fresh->bank_account_id)->toBeNull();
});

it('stores the inr rate', function () {
    $c = ivClient();
    expect(ivSvc()->create($c, [ivLine($c, 100)->id], [], 0.0, null, '83.5')->fresh()->exchange_rate)->toBe('83.500000');
});
