<?php

use App\Enums\PaymentType;
use App\Livewire\Payments\Index;
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
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'INR', 'billing_mode' => 'fixed']);
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
    return Livewire::actingAs(test()->owner)->test(Index::class)->assertOk()
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

it('adds a document later from the row', function () {
    $p = pdPayment();
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()
        ->set("rowDocs.{$p->id}", [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')])
        ->call('addDocument', $p->id)->assertHasNoErrors()->assertSee('late.pdf');
    expect($p->documents()->count())->toBe(1);
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
    pdForm([UploadedFile::fake()->create('../../evil<script>.pdf', 10, 'application/pdf')])->call('save')->assertHasNoErrors()
        ->assertDontSee('<script>', false)->assertSee('&lt;script&gt;', false);
    $d = PaymentDocument::firstOrFail();
    expect($d->original_name)->not->toContain('/')->and($d->original_name)->not->toContain('..')
        ->and($d->path)->not->toContain('evil')->and(substr($d->path, strlen('payment-documents/')))->not->toContain('/');
});

it('removes a document and its file only', function () {
    $p = pdPayment();
    $a = pdDoc($p, 'a.pdf');
    $b = pdDoc($p, 'b.pdf');
    Log::spy();
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->call('removeDocument', $a->id);
    expect(PaymentDocument::find($a->id))->toBeNull()->and(PaymentDocument::find($b->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($a->path);
    Storage::disk('local')->assertExists($b->path);
    Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'payment document removed' && $c === ['payment_id' => $p->id, 'name' => 'a.pdf', 'user_id' => $this->owner->id])->once();
});

it('404s when removing an unknown document', function () {
    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->call('removeDocument', 999);
});

it('forbids removing and uploading for non-owners', function () {
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Index::class)->assertForbidden();
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
        expect(validator(['f' => $file], ['f' => Index::DOC_RULE])->fails())->toBeTrue();
        unlink($path);
    }
});
