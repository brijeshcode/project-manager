<?php

use App\Enums\PaymentType;
use App\Livewire\Payments\Form;
use App\Livewire\Payments\Index;
use App\Livewire\Portal\Payments as PortalPayments;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentDocument;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'INR', 'billing_mode' => 'fixed', 'portal_features' => array_keys(Client::PORTAL_FEATURES)]);
});

function pdPayment(array $o = []): Payment
{
    return Payment::create($o + ['client_id' => test()->client->id, 'amount_cents' => 1000, 'paid_on' => '2026-11-01', 'type' => PaymentType::Project]);
}

function pdDoc(Payment $p, string $name = 'a.pdf'): PaymentDocument
{
    $path = 'payment-documents/'.str()->random(20).'.pdf';
    Storage::disk('local')->put($path, 'PDFDATA');

    return $p->documents()->create(['path' => $path, 'original_name' => $name, 'uploaded_by' => test()->owner->id]);
}

function pdForm(array $files)
{
    return Livewire::actingAs(test()->owner)->test(Form::class)->assertOk()
        ->set('client_id', test()->client->id)->set('amount', '10.00')->set('newDocs', $files);
}

it('records a payment with two documents on the private disk', function () {
    pdForm([UploadedFile::fake()->create('r1.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('r2.png')])
        ->call('save')->assertHasNoErrors();
    $docs = PaymentDocument::all();
    expect($docs)->toHaveCount(2)->and(Payment::firstOrFail()->documents)->toHaveCount(2);
    foreach ($docs as $d) {
        expect($d->path)->toStartWith('payment-documents/');
        Storage::disk('local')->assertExists($d->path);
        Storage::disk('public')->assertMissing($d->path);
        expect($d->uploaded_by)->toBe($this->owner->id);
    }
});

it('adds a document later from the edit page', function () {
    $p = pdPayment(['currency' => 'INR', 'exchange_rate' => '1', 'local_amount_cents' => 1000, 'local_currency' => 'INR']);
    pdDoc($p, 'first.pdf');
    Livewire::actingAs($this->owner)->test(Form::class, ['payment' => $p])->assertOk()->assertSee('first.pdf')
        ->set('newDocs', [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')])
        ->call('save')->assertHasNoErrors()->assertRedirect(route('payments.show', $p));
    expect($p->documents()->pluck('original_name')->all())->toBe(['first.pdf', 'late.pdf']);
});

it('index shows a documents link, not upload controls', function () {
    $p = pdPayment();
    pdDoc($p, 'one.pdf');
    pdDoc($p, 'two.pdf');
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()
        ->assertSee('View 2 documents')->assertSee(route('payments.show', $p).'#documents', false)
        ->assertDontSee('type="file"', false)->assertDontSee('Add document');
});

it('rejects hostile, oversize and too many uploads', function () {
    foreach ([
        UploadedFile::fake()->create('x.zip', 10, 'application/zip'),
        UploadedFile::fake()->create('x.php', 10, 'application/x-php'),
        UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
    ] as $f) {
        pdForm([$f])->call('save')->assertHasErrors();
    }
    pdForm(array_map(fn ($i) => UploadedFile::fake()->create("f$i.pdf", 1, 'application/pdf'), range(1, 6)))->call('save')->assertHasErrors();
    expect(PaymentDocument::count())->toBe(0)->and(Payment::count())->toBe(0);
});

it('sanitises the original name and stores under a random name', function () {
    pdForm([UploadedFile::fake()->create('../../evil<script>.pdf', 10, 'application/pdf')])->call('save')->assertHasNoErrors();
    $this->actingAs($this->owner)->get(route('payments.show', Payment::firstOrFail()))->assertOk()
        ->assertDontSee('evil<script>', false)->assertSee('evil&lt;script&gt;', false);
    $d = PaymentDocument::firstOrFail();
    expect($d->original_name)->not->toContain('/')->and($d->original_name)->not->toContain('..')
        ->and($d->path)->not->toContain('evil')->and(substr($d->path, strlen('payment-documents/')))->not->toContain('/');
});

function pdEdit(Payment $p)
{
    return Livewire::actingAs(test()->owner)->test(Form::class, ['payment' => $p])->assertOk();
}

it('removes a document and its file only', function () {
    $p = pdPayment();
    $a = pdDoc($p, 'a.pdf');
    $b = pdDoc($p, 'b.pdf');
    Log::spy();
    pdEdit($p)->call('removeDocument', $a->id);
    expect(PaymentDocument::find($a->id))->toBeNull()->and(PaymentDocument::find($b->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($a->path);
    Storage::disk('local')->assertExists($b->path);
    Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'payment document removed' && $c === ['payment_id' => $p->id, 'name' => 'a.pdf', 'user_id' => $this->owner->id])->once();
});

it("404s when removing an unknown document or another payment's", function () {
    $other = pdDoc(pdPayment());
    foreach ([999, $other->id] as $id) {
        try {
            pdEdit(pdPayment())->call('removeDocument', $id);
            $this->fail('expected 404');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
    }
    expect(PaymentDocument::find($other->id))->not->toBeNull();
});

it('forbids removing and uploading for non-owners', function () {
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Form::class)->assertForbidden();

    $p = pdPayment();
    $d = pdDoc($p);
    $c = pdEdit($p);
    $c2 = pdEdit($p);
    $c->set('newDocs', [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]);
    $this->actingAs($dev);
    $c->call('save')->assertForbidden();
    $c2->call('removeDocument', $d->id)->assertForbidden();
    expect($p->documents()->count())->toBe(1)->and(PaymentDocument::find($d->id))->not->toBeNull();
    Storage::disk('local')->assertExists($d->path);
});

it('404s the download when the file is missing from disk', function () {
    $d = pdDoc(pdPayment());
    Storage::disk('local')->delete($d->path);
    $this->actingAs($this->owner)->get(route('payment-documents.download', $d->id))->assertNotFound();
});

it('deletes the files when a payment is deleted', function () {
    $p = pdPayment();
    $d = pdDoc($p);
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->call('deletePayment', $p->id);
    expect(PaymentDocument::count())->toBe(0);
    Storage::disk('local')->assertMissing($d->path);
});

it('lets the owner download with nosniff', function () {
    $d = pdDoc(pdPayment(), 'receipt.pdf');
    $r = $this->actingAs($this->owner)->get(route('payment-documents.download', $d->id));
    $r->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($r->streamedContent())->toBe('PDFDATA');
});

it('blocks everyone else from downloads', function () {
    $d = pdDoc(pdPayment());
    $url = route('payment-documents.download', $d->id);
    $this->actingAs(User::factory()->create()->assignRole('developer'))->get($url)->assertForbidden();
    $cu = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');
    $this->actingAs($cu)->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect('/login');
    $this->actingAs($this->owner)->get('/payment-documents/abc/download')->assertNotFound();
});

it('shows nothing about documents on the client portal', function () {
    $d = pdDoc(pdPayment(), 'secret-receipt.pdf');
    $cu = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');
    $this->actingAs($cu)->get(route('portal.payments'))->assertOk()
        ->assertDontSee('secret-receipt')->assertDontSee('payment-documents')->assertDontSee('Documents')->assertDontSee('Add document');
});

it('rejects content that is not a document even with a pdf name', function () {
    foreach (["PK\x03\x04zipdata", '<?php echo 1;'] as $i => $content) {
        $path = tempnam(sys_get_temp_dir(), 'pd');
        file_put_contents($path, $content);
        $file = new UploadedFile($path, 'x.pdf', 'application/pdf', null, true);
        expect(validator(['f' => $file], ['f' => Form::DOC_RULE])->fails())->toBeTrue();
        unlink($path);
    }
});

it('saves the show-documents-to-client flag on create and edit', function () {
    pdForm([])->set('documents_visible_to_client', true)->call('save')->assertHasNoErrors();
    $p = Payment::firstOrFail();
    expect($p->documents_visible_to_client)->toBeTrue();
    pdEdit($p)->assertSet('documents_visible_to_client', true)->set('documents_visible_to_client', false)->call('save')->assertHasNoErrors();
    expect($p->fresh()->documents_visible_to_client)->toBeFalse();
});

it('shows and downloads documents on the portal only when the flag is on, own client only', function () {
    $hidden = pdDoc(pdPayment(), 'hidden.pdf');
    $shown = pdDoc(pdPayment(['documents_visible_to_client' => true]), 'shown.pdf');
    $other = Client::create(['name' => 'Other', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    $foreign = pdDoc(pdPayment(['client_id' => $other->id, 'documents_visible_to_client' => true]), 'foreign.pdf');
    $cu = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');

    $this->actingAs($cu)->get(route('payment-documents.download', $shown->id))->assertForbidden();
    $t = Livewire::actingAs($cu)->test(PortalPayments::class)->assertOk()
        ->assertSee('shown.pdf')->assertDontSee('hidden.pdf')->assertDontSee('foreign.pdf')->assertDontSee('payment-documents');
    $t->call('download', $shown->id)->assertFileDownloaded('shown.pdf');
    foreach ([$hidden->id, $foreign->id, 999] as $id) {
        try {
            Livewire::actingAs($cu)->test(PortalPayments::class)->call('download', $id);
            $this->fail('expected 404');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
    }
    $dev = User::factory()->create(['client_id' => $this->client->id])->assignRole('developer');
    Livewire::actingAs($dev)->test(PortalPayments::class)->assertForbidden();
});
