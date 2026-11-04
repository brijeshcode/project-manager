<?php

use App\Enums\AccessScope;
use App\Livewire\Resources\Panel;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Label;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SECRET = 'hunter2-s3cret-PASSWORD';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(TaxonomySeeder::class);
});

/** Project with every kind of user; $res is created by $creator with viewers staffViewer + clientViewer. */
function resFix(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $mk = function (string $role, array $attrs = [], bool $member = true) use ($project) {
        $u = User::factory()->create($attrs);
        $u->assignRole($role);
        $member && ProjectMember::create(['project_id' => $project->id, 'user_id' => $u->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);

        return $u;
    };
    $f = [
        'client' => $client, 'project' => $project,
        'admin' => $mk('super_admin', [], false),
        'pm' => $mk('project_manager'),
        'creator' => $mk('developer'),
        'staffViewer' => $mk('developer'),
        'memberNon' => $mk('developer'),
        'outsider' => $mk('developer', [], false),
        'clientViewer' => $mk('client', ['client_id' => $client->id], false),
        'clientNon' => $mk('client', ['client_id' => $client->id], false),
    ];
    $res = Resource::create(['project_id' => $project->id, 'title' => 'Prod DB', 'value' => SECRET, 'created_by' => $f['creator']->id]);
    $res->viewers()->sync([$f['staffViewer']->id, $f['clientViewer']->id]);
    $f['res'] = $res;

    return $f;
}

function panel(User $u, Project $p)
{
    return Livewire::actingAs($u)->test(Panel::class, ['project' => $p]);
}

it('stores the value encrypted (raw column is not plaintext) and decrypts through the model', function () {
    $f = resFix();
    $raw = DB::table('resources')->where('id', $f['res']->id)->value('value');
    expect($raw)->not->toContain(SECRET)->and(Resource::find($f['res']->id)->value)->toBe(SECRET);
});

it('encrypts attachment bytes on disk and serves them decrypted to authorised viewers only', function () {
    Storage::fake('local');
    $f = resFix();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $a = $f['res']->attachEncrypted($png, 'pic.png', 'image/png', $f['creator']->id);

    expect(Storage::disk('local')->get($a->path))->not->toBe($png)->and($a->encrypted)->toBeTrue();

    $r = $this->actingAs($f['staffViewer'])->get($a->url())->assertOk();
    expect($r->baseResponse->getContent())->toBe($png);
    expect($r->headers->get('Content-Type'))->toBe('image/png')->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $pdf = $f['res']->attachEncrypted('%PDF-1.4 secret pdf', 'doc.pdf', 'application/pdf', $f['creator']->id);
    $r = $this->actingAs($f['clientViewer'])->get($pdf->url())->assertOk();
    expect($r->baseResponse->getContent())->toBe('%PDF-1.4 secret pdf')->and($r->headers->get('Content-Disposition'))->toContain('attachment');
});

it('uploads from the add form land on a draft resource that save() promotes and cancel deletes', function () {
    Storage::fake('local');
    $f = resFix();
    $c = panel($f['creator'], $f['project'])->call('openAdd')
        ->set('upload', UploadedFile::fake()->image('shot.png'))->call('storeUpload');
    $draft = Resource::where('is_draft', true)->first();
    expect($draft)->not->toBeNull()->and($draft->attachments()->count())->toBe(1);
    $path = $draft->attachments()->first()->path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();
    expect(Resource::visibleTo($f['admin'])->pluck('id')->all())->not->toContain($draft->id);

    $c->set('form.title', 'With image')->set('form.value', 'see image')->call('save')->assertHasNoErrors();
    expect($draft->fresh()->is_draft)->toBeFalse()->and($draft->fresh()->title)->toBe('With image');

    $c2 = panel($f['creator'], $f['project'])->call('openAdd')
        ->set('upload', UploadedFile::fake()->image('x.png'))->call('storeUpload');
    $d2 = Resource::where('is_draft', true)->first();
    $p2 = $d2->attachments()->first()->path;
    $c2->call('cancelForm');
    expect(Resource::where('is_draft', true)->count())->toBe(0)->and(Storage::disk('local')->exists($p2))->toBeFalse();
});

it('keeps the value out of the initial HTML and returns it only when a row is revealed', function () {
    $f = resFix();
    $c = panel($f['staffViewer'], $f['project']);
    expect($c->html())->toContain('Prod DB')->not->toContain(SECRET);
    $c->call('reveal', $f['res']->id)->assertReturned(fn ($r) => str_contains($r['html'], SECRET) && $r['raw'] === SECRET && $r['log'] === '');
});

it('logs a reveal once per user per five minutes and never writes the value to activities', function () {
    $f = resFix();
    $c = panel($f['staffViewer'], $f['project']);
    $c->call('reveal', $f['res']->id)->call('reveal', $f['res']->id);
    expect(Activity::where('event', 'viewed')->count())->toBe(1);

    $this->travel(6)->minutes();
    $c->call('reveal', $f['res']->id);
    expect(Activity::where('event', 'viewed')->count())->toBe(2);

    panel($f['creator'], $f['project'])->call('reveal', $f['res']->id);
    expect(Activity::where('event', 'viewed')->where('user_id', $f['creator']->id)->count())->toBe(1);

    // edit + viewers + delete are logged too, secrets never
    panel($f['creator'], $f['project'])->call('startEdit', $f['res']->id)
        ->set('form.value', 'new-'.SECRET)->set('form.viewers', [(string) $f['staffViewer']->id])->call('save');
    $f['res']->refresh();
    expect($f['res']->activities()->pluck('event')->all())->toContain('edited', 'viewer_removed');
    panel($f['creator'], $f['project'])->call('deleteResource', $f['res']->id);
    expect(Activity::where('event', 'deleted')->exists())->toBeTrue();
    foreach (Activity::all() as $a) {
        expect(json_encode($a->toArray()))->not->toContain('s3cret');
    }
});

it('the access log is returned to editors only and shows who revealed', function () {
    $f = resFix();
    panel($f['staffViewer'], $f['project'])->call('reveal', $f['res']->id);
    panel($f['creator'], $f['project'])->call('reveal', $f['res']->id)
        ->assertReturned(fn ($r) => str_contains($r['log'], $f['staffViewer']->name) && str_contains($r['log'], 'viewed the value'));
    panel($f['staffViewer'], $f['project'])->call('reveal', $f['res']->id)->assertReturned(fn ($r) => $r['log'] === '');
});

it('permission matrix: view, edit, delete, attachment download', function () {
    Storage::fake('local');
    $f = resFix();
    $a = $f['res']->attachEncrypted('bytes', 'a.png', 'image/png', null);
    $id = $f['res']->id;

    // who may view / edit
    $view = ['admin' => true, 'pm' => true, 'creator' => true, 'staffViewer' => true, 'memberNon' => false, 'outsider' => false, 'clientViewer' => true, 'clientNon' => false];
    $edit = ['admin' => true, 'pm' => true, 'creator' => true, 'staffViewer' => false, 'memberNon' => false, 'outsider' => false, 'clientViewer' => false, 'clientNon' => false];
    foreach ($view as $who => $can) {
        expect($f['res']->canView($f[$who]))->toBe($can, "view $who");
        expect($f['res']->canEdit($f[$who]))->toBe($edit[$who], "edit $who");
        $this->actingAs($f[$who])->get($a->url())->assertStatus($can ? 200 : 403);
    }

    // reveal: those who can't view get a 404 (outsider can't even open the panel)
    foreach (['memberNon', 'clientNon'] as $who) {
        expect(fn () => panel($f[$who], $f['project'])->call('reveal', $id))->toThrow(ModelNotFoundException::class);
        expect(panel($f[$who], $f['project'])->html())->not->toContain('Prod DB');
    }
    panel($f['outsider'], $f['project'])->assertForbidden();

    // viewers (staff and client) cannot edit or delete
    foreach (['staffViewer', 'clientViewer'] as $who) {
        panel($f[$who], $f['project'])->call('startEdit', $id)->assertForbidden();
        panel($f[$who], $f['project'])->call('deleteResource', $id)->assertForbidden();
        panel($f[$who], $f['project'])->call('toggleViewer', $id, $f['memberNon']->id)->assertForbidden();
    }
    // clients cannot add
    panel($f['clientViewer'], $f['project'])->call('openAdd')->assertForbidden();

    // editors can
    panel($f['pm'], $f['project'])->call('toggleViewer', $id, $f['memberNon']->id);
    expect($f['res']->viewers()->pluck('users.id')->all())->toContain($f['memberNon']->id);
    panel($f['admin'], $f['project'])->call('deleteResource', $id);
    expect(Resource::count())->toBe(0)->and(Attachment::count())->toBe(0);
});

it('a creator who is no longer a project member loses access', function () {
    $f = resFix();
    ProjectMember::where('user_id', $f['creator']->id)->update(['active' => false]);
    expect($f['res']->canEdit($f['creator']))->toBeFalse()->and($f['res']->canView($f['creator']))->toBeFalse();
});

it('creates a resource with labels and viewers and the creator becomes its editor', function () {
    $f = resFix();
    $prod = Label::where('name', 'Production')->value('id');
    panel($f['memberNon'], $f['project'])->call('openAdd')
        ->set('form.title', 'New key')->set('form.value', 'abc')
        ->set('form.labels', [(string) $prod])->set('form.viewers', [(string) $f['clientViewer']->id])
        ->call('save')->assertHasNoErrors();
    $r = Resource::where('title', 'New key')->first();
    expect($r->created_by)->toBe($f['memberNon']->id)->and($r->labels()->pluck('labels.id')->all())->toBe([$prod])
        ->and($r->viewers()->pluck('users.id')->all())->toBe([$f['clientViewer']->id])
        ->and($r->canEdit($f['memberNon']))->toBeTrue();
});

it('rejects viewers who are not project members or client users, and an empty title or value', function () {
    $f = resFix();
    panel($f['creator'], $f['project'])->call('openAdd')->set('form.title', 'x')->set('form.value', 'y')
        ->set('form.viewers', [(string) $f['outsider']->id])->call('save')->assertHasErrors('form.viewers.0');
    panel($f['creator'], $f['project'])->call('openAdd')->call('save')->assertHasErrors(['form.title', 'form.value']);
});

it('filters by label and searches by title, within what the user may see', function () {
    $f = resFix();
    $stg = Label::where('name', 'Staging')->value('id');
    $r2 = Resource::create(['project_id' => $f['project']->id, 'title' => 'Staging login', 'value' => 'v', 'created_by' => $f['creator']->id]);
    $r2->labels()->sync([$stg]);
    $hidden = Resource::create(['project_id' => $f['project']->id, 'title' => 'Hidden staging', 'value' => 'v', 'created_by' => $f['creator']->id]);
    $hidden->labels()->sync([$stg]);
    $r2->viewers()->sync([$f['staffViewer']->id]);

    $c = panel($f['staffViewer'], $f['project']);
    expect($c->get('resources')->pluck('title')->all())->toBe(['Staging login', 'Prod DB']);
    $c->set('labelFilter', (string) $stg);
    expect($c->get('resources')->pluck('title')->all())->toBe(['Staging login']);
    $c->set('labelFilter', '')->set('search', 'prod');
    expect($c->get('resources')->pluck('title')->all())->toBe(['Prod DB']);
    $c->set('search', '100%');
    expect($c->get('resources'))->toHaveCount(0);
});

it('seeds the default environment labels', function () {
    expect(Label::whereIn('name', ['Production', 'Staging', 'Development'])->count())->toBe(3);
});

it('renders markdown safely: no raw HTML, no javascript: links', function () {
    $f = resFix();
    $f['res']->update(['value' => "<script>alert(1)</script><img src=x onerror=alert(2)>\n\n[click](javascript:alert(3)) **bold**"]);
    panel($f['creator'], $f['project'])->call('reveal', $f['res']->id)->assertReturned(function ($r) {
        return ! str_contains($r['html'], '<script') && ! str_contains($r['html'], 'onerror') && ! str_contains($r['html'], 'javascript:') && str_contains($r['html'], '<strong>bold</strong>');
    });
});

it('the project Resources tab renders and the client portal shows only the client viewer their resources', function () {
    $f = resFix();
    $this->actingAs($f['creator'])->get(route('projects.show', ['project' => $f['project'], 'tab' => 'resources']))
        ->assertOk()->assertSee('Prod DB')->assertSee('Add resource')->assertDontSee(SECRET);
    $this->actingAs($f['clientViewer'])->get(route('portal.project', $f['project']))->assertOk()->assertSee('Prod DB')->assertDontSee('Add resource');
    $this->actingAs($f['clientNon'])->get(route('portal.project', $f['project']))->assertOk()->assertDontSee('Prod DB');
});

it('removed the old resource items structure', function () {
    expect(Schema::hasTable('resource_items'))->toBeFalse()->and(Route::has('resource-items.download'))->toBeFalse();
});
