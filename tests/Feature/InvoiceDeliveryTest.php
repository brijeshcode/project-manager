<?php

use App\Livewire\Invoices\Show;
use App\Mail\InvoiceMail;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function idOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function idClient(array $o = []): Client
{
    return Client::create($o + ['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

function idInvoice(Client $c, string $desc = 'Design work'): Invoice
{
    $l = BillableLine::create(['client_id' => $c->id, 'description' => $desc, 'quantity' => 1, 'unit_price_cents' => 123456, 'amount_cents' => 123456]);

    return app(InvoiceService::class)->create($c, [$l->id]);
}

function idUser(string $role, ?int $clientId = null): User
{
    $u = User::factory()->create(['client_id' => $clientId]);

    return $u->assignRole($role);
}

it('queues exactly one mail with pdf, body and subject on create', function () {
    Mail::fake();
    $inv = idInvoice(idClient());
    Mail::assertQueued(InvoiceMail::class, 1);
    Mail::assertQueued(InvoiceMail::class, fn ($m) => $m->hasTo('a@acme.test'));
    expect($inv->fresh()->emailed_at)->not->toBeNull();

    $mail = new InvoiceMail($inv->fresh());
    expect($mail->envelope()->subject)->toBe("Invoice {$inv->number} from ".config('billing.company_name'));
    $html = $mail->render();
    expect($html)->toContain('Design work')->toContain($inv->number)->toContain('$1,234.56');
    $att = $mail->attachments();
    expect($att)->toHaveCount(1)->and($att[0]->as)->toBe("{$inv->number}.pdf")->and($att[0]->mime)->toBe('application/pdf');
    $bytes = null;
    $att[0]->attachWith(fn () => null, function ($data) use (&$bytes) { $bytes = $data(); });
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

it('pdf bytes are a pdf', function () {
    Mail::fake();
    expect(substr(InvoiceMail::pdfBytes(idInvoice(idClient())), 0, 4))->toBe('%PDF');
});

it('escapes descriptions in the email body', function () {
    Mail::fake();
    $inv = idInvoice(idClient(), '<script>alert(1)</script>');
    $html = (new InvoiceMail($inv))->render();
    expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;');
});

it('sends only once', function () {
    Mail::fake();
    $inv = idInvoice(idClient());
    $svc = app(InvoiceService::class);
    // stale instance: emailed_at null in memory, already set in the DB -> the atomic claim must refuse
    $stale = $inv->fresh();
    $stale->emailed_at = null;
    expect($svc->send($stale))->toBeFalse();
    Mail::assertQueued(InvoiceMail::class, 1);
    // two sequential sends on fresh models after clearing: exactly one more
    Invoice::whereKey($inv->id)->update(['emailed_at' => null]);
    expect($svc->send($inv->fresh()))->toBeTrue();
    expect($svc->send($inv->fresh()))->toBeFalse();
    Mail::assertQueued(InvoiceMail::class, 2);
});

it('does not send void invoices or clients without email', function () {
    Mail::fake();
    $c = idClient();
    $inv = idInvoice($c);
    $inv->update(['emailed_at' => null, 'status' => 'void']);
    expect(app(InvoiceService::class)->send($inv->fresh()))->toBeFalse();
    expect(app(InvoiceService::class)->resend($inv->fresh()))->toBeFalse();
    Mail::assertQueued(InvoiceMail::class, 1); // only the create-time one
});

it('creates invoice without email when client has no contact email and shows warning', function () {
    Mail::fake();
    $inv = idInvoice(idClient(['contact_email' => null]));
    Mail::assertNothingQueued();
    expect($inv->fresh()->emailed_at)->toBeNull();
    Livewire::actingAs(idOwner())->test(Show::class, ['invoice' => $inv])->assertSee('No contact email');
});

it('does not fail create when dispatch throws, and leaves emailed_at null', function () {
    $this->mock(Mailer::class, fn ($m) => $m->shouldReceive('queue')->andThrow(new RuntimeException('boom')));
    Mail::swap(app(Mailer::class));
    $inv = idInvoice(idClient());
    expect($inv->exists)->toBeTrue()->and($inv->fresh()->emailed_at)->toBeNull();
});

it('resend works for owner and re-sets emailed_at', function () {
    Mail::fake();
    $inv = idInvoice(idClient());
    Invoice::whereKey($inv->id)->update(['emailed_at' => '2020-01-01 00:00:00']);
    Livewire::actingAs(idOwner())->test(Show::class, ['invoice' => $inv])->call('resend');
    Mail::assertQueued(InvoiceMail::class, 2);
    expect($inv->fresh()->emailed_at->year)->toBeGreaterThan(2020);
});

it('resend is refused for non-owners at action level', function () {
    Mail::fake();
    $inv = idInvoice(idClient());
    $t = Livewire::actingAs(idOwner())->test(Show::class, ['invoice' => $inv]);
    $this->actingAs(idUser('developer'));
    $t->call('resend')->assertForbidden();
    Mail::assertQueued(InvoiceMail::class, 1);
});

describe('pdf route', function () {
    beforeEach(fn () => Mail::fake());

    it('serves the owner', function () {
        $inv = idInvoice(idClient());
        $r = $this->actingAs(idOwner())->get(route('invoices.pdf', $inv))->assertOk();
        expect($r->headers->get('Content-Type'))->toBe('application/pdf');
        expect($r->headers->get('Content-Disposition'))->toBe("inline; filename=\"{$inv->number}.pdf\"");
        expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
    });

    it('serves the own client but not others, staff, or unlinked users', function () {
        $c = idClient();
        $inv = idInvoice($c);
        $other = idClient(['name' => 'Other']);
        $this->actingAs(idUser('client', $c->id))->get(route('invoices.pdf', $inv))->assertOk();
        $this->actingAs(idUser('client', $other->id))->get(route('invoices.pdf', $inv))->assertNotFound();
        $this->actingAs(idUser('client'))->get(route('invoices.pdf', $inv))->assertNotFound();
        $this->actingAs(idUser('developer'))->get(route('invoices.pdf', $inv))->assertNotFound();
        $this->actingAs(idUser('project_manager'))->get(route('invoices.pdf', $inv))->assertNotFound();
    });

    it('redirects guests', function () {
        $this->get(route('invoices.pdf', idInvoice(idClient())))->assertRedirect(route('login'));
    });

    it('hides void invoices from clients only', function () {
        $c = idClient();
        $inv = idInvoice($c);
        app(InvoiceService::class)->void($inv);
        $this->actingAs(idUser('client', $c->id))->get(route('invoices.pdf', $inv))->assertNotFound();
        $this->actingAs(idOwner())->get(route('invoices.pdf', $inv))->assertOk();
    });
});
