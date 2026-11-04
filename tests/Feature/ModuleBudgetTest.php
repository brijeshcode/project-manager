<?php

use App\Enums\AccessScope;
use App\Exceptions\OverBudget;
use App\Livewire\Modules\Show as ModuleShow;
use App\Livewire\Projects\Form as ProjectForm;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Sprints\Panel as SprintPanel;
use App\Livewire\Tasks\Form as TaskForm;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Activity;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\ModuleBudgetChange;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Sprint;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\BillableService;
use App\Services\ModuleBudget;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
    $this->admin = User::factory()->create()->assignRole('super_admin');
});

/** A project of a fixed-price (default) or hourly client with one module; returns [project, module]. */
function mbSetup(string $mode = 'fixed', array $module = ['budget_amount' => '100']): array
{
    $client = Client::create(['name' => 'C'.uniqid(), 'currency' => 'USD', 'billing_mode' => $mode]);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Northwind Storefront', 'key' => Project::where('key', 'NWS')->exists() ? 'NW'.(Project::count() + 1) : 'NWS', 'status' => 'active']);
    $m = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1] + $module);

    return [$project, $m];
}

function mbTask(Module $m, User $by, array $a = []): Task
{
    return Task::create($a + ['module_id' => $m->id, 'creator_id' => $by->id, 'title' => 'T', 'status' => 'published', 'estimate_hours' => 2]);
}

function mbDone(Project $p): int
{
    return $p->columns->firstWhere('stage', 'done')->id;
}

function mbMember(Project $p, string $role): User
{
    $u = User::factory()->create()->assignRole($role);
    ProjectMember::create(['project_id' => $p->id, 'user_id' => $u->id, 'role_on_project' => $role, 'access_scope' => AccessScope::All, 'active' => true]);

    return $u;
}

// ── the service ──────────────────────────────────────────────────────────────

it('computes allocated, remaining and wouldExceed; proposed add-ons are exempt, accepted ones count', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '30']);
    mbTask($m, $this->admin, ['budget_amount' => '20']);
    mbTask($m, $this->admin);                                                            // unpriced: 0
    mbTask($m, $this->admin, ['budget_amount' => '500', 'status' => 'draft']);           // drafts never count
    $addon = mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '40']);      // proposed: exempt
    $mb = new ModuleBudget($m);

    expect($mb->cap())->toBe(10000)->and($mb->allocated())->toBe(5000)->and($mb->remaining())->toBe(5000)
        ->and($mb->wouldExceed(null, 5000))->toBe(0)->and($mb->wouldExceed(null, 6000))->toBe(1000);

    $addon->acceptAddon($this->admin);
    expect($mb->cap())->toBe(14000)->and($mb->allocated())->toBe(9000)->and($mb->remaining())->toBe(5000);
});

it('has no cap when the module has no budget', function () {
    [$p, $m] = mbSetup('fixed', []);
    $t = mbTask($m, $this->admin, ['budget_amount' => '999']);
    $mb = new ModuleBudget($m);
    expect($mb->cap())->toBeNull()->and($mb->remaining())->toBeNull()->and($mb->wouldExceed($t->id, 99999999))->toBe(0);
    $mb->guard($t, 5); // no throw
});

it('guard refuses only edits that raise usage past the cap', function () {
    [$p, $m] = mbSetup();
    $a = mbTask($m, $this->admin, ['budget_amount' => '90']);
    $mb = new ModuleBudget($m);
    expect(fn () => $mb->guard($a, 10100))->toThrow(OverBudget::class);
    $mb->guard($a, 10000); // exactly the cap fits
    // legacy data already over the cap: lowering is still allowed, raising is not
    Task::whereKey($a->id)->update(['budget_amount' => '150']);
    $mb->guard($a->fresh(), 12000);
    expect(fn () => $mb->guard($a->fresh(), 16000))->toThrow(OverBudget::class);
});

it('distributes the remaining budget by hours: 100 over 2h/2h/4h gives 25/25/50 and never touches priced tasks', function () {
    [$p, $m] = mbSetup();
    $a = mbTask($m, $this->admin, ['title' => 'A', 'estimate_hours' => 2]);
    $b = mbTask($m, $this->admin, ['title' => 'B', 'estimate_hours' => 2]);
    $c = mbTask($m, $this->admin, ['title' => 'C', 'estimate_hours' => 4]);
    $mb = new ModuleBudget($m);

    $pv = $mb->distributePreview();
    expect($pv['ok'])->toBeTrue()->and(collect($pv['rows'])->pluck('cents')->all())->toBe([2500, 2500, 5000])
        ->and(Task::whereNotNull('budget_amount')->count())->toBe(0); // a preview stores nothing

    expect($mb->applyDistribute())->toBe(3);
    expect(Task::orderBy('id')->pluck('budget_amount')->map(fn ($v) => (float) $v)->all())->toBe([25.0, 25.0, 50.0]);

    // a priced task is never touched, and the share is computed on what remains
    [$p2, $m2] = mbSetup();
    $fixed = mbTask($m2, $this->admin, ['budget_amount' => '40', 'estimate_hours' => 9]);
    $x = mbTask($m2, $this->admin, ['estimate_hours' => 1]);
    $y = mbTask($m2, $this->admin, ['estimate_hours' => 3]);
    (new ModuleBudget($m2))->applyDistribute();
    expect((float) $fixed->fresh()->budget_amount)->toBe(40.0)->and((float) $x->fresh()->budget_amount)->toBe(15.0)->and((float) $y->fresh()->budget_amount)->toBe(45.0);
});

it('rounds half-up and lets the last task absorb the remainder so the parts sum exactly', function () {
    [$p, $m] = mbSetup();
    foreach (['A', 'B', 'C'] as $t) {
        mbTask($m, $this->admin, ['title' => $t, 'estimate_hours' => 1]);
    }
    $cents = collect((new ModuleBudget($m))->distributePreview()['rows'])->pluck('cents');
    expect($cents->all())->toBe([3333, 3333, 3334])->and($cents->sum())->toBe(10000);

    expect(ModuleBudget::split(1000, [1 => 100, 2 => 100, 3 => 100]))->toBe([1 => 333, 2 => 333, 3 => 334]);
    expect(array_sum(ModuleBudget::split(9999, [1 => 175, 2 => 330, 3 => 25, 4 => 710])))->toBe(9999);
});

it('shares equally only when every unpriced task has zero hours, otherwise zero-hour tasks get 0', function () {
    expect(ModuleBudget::split(100, [1 => 0, 2 => 0, 3 => 0, 4 => 0]))->toBe([1 => 25, 2 => 25, 3 => 25, 4 => 25]);
    expect(ModuleBudget::split(100, [1 => 0, 2 => 0, 3 => 0]))->toBe([1 => 33, 2 => 33, 3 => 34]);
    expect(ModuleBudget::split(100, [1 => 0, 2 => 300, 3 => 100]))->toBe([1 => 0, 2 => 75, 3 => 25]);
    expect(ModuleBudget::split(100, [1 => 300, 2 => 0]))->toBe([1 => 100, 2 => 0]);
    expect(ModuleBudget::split(100, []))->toBe([]);

    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['estimate_hours' => 0]);
    mbTask($m, $this->admin, ['estimate_hours' => 0]);
    expect(collect((new ModuleBudget($m))->distributePreview()['rows'])->pluck('cents')->all())->toBe([5000, 5000]);
});

it('explains why distribute does not apply', function () {
    [$p, $m] = mbSetup();
    expect((new ModuleBudget($m))->distributePreview())->toMatchArray(['ok' => false, 'reason' => 'Every task already has a value.']);
    mbTask($m, $this->admin, ['budget_amount' => '100']);
    mbTask($m, $this->admin);
    expect((new ModuleBudget($m))->distributePreview()['reason'])->toBe('No budget remains to distribute.');
    [$p, $nocap] = mbSetup('fixed', []);
    expect((new ModuleBudget($nocap))->distributePreview()['reason'])->toBe('Set the module budget first.');
    [$p, $h] = mbSetup('hourly', ['budget_hours' => 10, 'hourly_rate' => '50']);
    expect((new ModuleBudget($h))->distributePreview()['ok'])->toBeFalse();
});

it('previews a rebalance of the other tasks and never changes done tasks', function () {
    [$p, $m] = mbSetup();
    $done = mbTask($m, $this->admin, ['title' => 'Done', 'budget_amount' => '20', 'board_column_id' => mbDone($p)]);
    $a = mbTask($m, $this->admin, ['title' => 'A', 'budget_amount' => '30', 'estimate_hours' => 1]);
    $b = mbTask($m, $this->admin, ['title' => 'B', 'budget_amount' => '30', 'estimate_hours' => 3]);
    $new = mbTask($m, $this->admin, ['title' => 'New', 'estimate_hours' => 1]);
    $mb = new ModuleBudget($m);

    // New wants 60: pool = 100 - 60 - 20 (done) = 20, split 1h:3h -> 5 / 15
    $pv = $mb->rebalancePreview($new->id, 6000);
    expect($pv['ok'])->toBeTrue()->and(collect($pv['rows'])->map(fn ($r) => [$r['task']->title, $r['old'], $r['new']])->all())->toBe([['A', 3000, 500], ['B', 3000, 1500]]);
    expect((float) $a->fresh()->budget_amount)->toBe(30.0); // preview stores nothing

    $mb->applyRebalance($new->id, 6000);
    expect((float) $a->fresh()->budget_amount)->toBe(5.0)->and((float) $b->fresh()->budget_amount)->toBe(15.0)->and((float) $done->fresh()->budget_amount)->toBe(20.0);
});

it('refuses a rebalance that cannot work', function () {
    [$p, $m] = mbSetup();
    $done = mbTask($m, $this->admin, ['budget_amount' => '90', 'board_column_id' => mbDone($p)]);
    $open = mbTask($m, $this->admin, ['budget_amount' => '10']);
    $new = mbTask($m, $this->admin);
    $mb = new ModuleBudget($m);
    $pv = $mb->rebalancePreview($new->id, 5000); // done alone (90) + 50 exceeds the cap
    expect($pv['ok'])->toBeFalse()->and($pv['reason'])->toContain('Done tasks keep their value');
    expect(fn () => $mb->applyRebalance($new->id, 5000))->toThrow(Illuminate\Validation\ValidationException::class);
    expect((float) $open->fresh()->budget_amount)->toBe(10.0);

    $open->delete();
    expect($mb->rebalancePreview($new->id, 5000)['reason'])->toContain('no other open tasks');
});

// ── block flow through the components ────────────────────────────────────────

/** Module 100 with A=60 priced and B unpriced; returns [project, module, A, B]. */
function mbBlockSetup(User $by): array
{
    [$p, $m] = mbSetup();
    $a = mbTask($m, $by, ['title' => 'A', 'budget_amount' => '60', 'estimate_hours' => 3]);
    $b = mbTask($m, $by, ['title' => 'B', 'estimate_hours' => 1]);

    return [$p, $m, $a, $b];
}

it('refuses a task value that exceeds the module budget and offers the three actions', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '60')->call('savePanel')
        ->assertSet('panel', 'estimate')
        ->assertSet('block.over', 2000)
        ->assertSee('This puts Checkout $20.00 over its budget.')
        ->assertSee('Raise the module budget to $120.00')
        ->assertSee('Create an add-on task')
        ->assertSee('Rebalance the other tasks');
    expect($b->fresh()->budget_amount)->toBeNull(); // nothing saved
});

it('action 1: raising the module budget is logged with the reason and then saves the task', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '60')->call('savePanel')
        ->set('blockReason', 'Client approved extra scope')->call('raiseBlocked')
        ->assertSet('block', null)->assertSet('panel', null);
    expect((float) $m->fresh()->budget_amount)->toBe(120.0)->and((float) $b->fresh()->budget_amount)->toBe(60.0);
    $change = ModuleBudgetChange::where('module_id', $m->id)->latest('id')->first();
    expect($change->field)->toBe('budget_amount')->and($change->old)->toBe('100.00')->and($change->new)->toBe('120.00')->and($change->reason)->toBe('Client approved extra scope');
});

it('action 1 needs a reason', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '60')->call('savePanel')
        ->set('blockReason', '  ')->call('raiseBlocked')->assertHasErrors('blockReason');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
});

it('action 2: the add-on link opens the create form prefilled as an out-of-scope add-on', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '60')->call('savePanel');
    $c->assertSeeHtml('addon=1')->assertSeeHtml('amount=60.00');

    Livewire::actingAs($this->admin)->withQueryParams(['module' => $m->id, 'addon' => 1, 'amount' => '60.00', 'title' => 'Add-on: B'])->test(TaskForm::class)
        ->assertSet('scope', 'out')->assertSet('budget_amount', 60.0)->assertSet('title', 'Add-on: B')->assertSet('module_id', $m->id);
});

it('action 3: previews the exact new amounts, applies only on confirm, and saves the task', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '60')->call('savePanel')
        ->call('showRebalance')->assertSee('$60.00')->assertSee('$40.00');
    expect((float) $a->fresh()->budget_amount)->toBe(60.0); // still nothing applied
    $c->call('applyBlockedRebalance')->assertSet('block', null);
    expect((float) $a->fresh()->budget_amount)->toBe(40.0)->and((float) $b->fresh()->budget_amount)->toBe(60.0)->and((float) $m->fresh()->budget_amount)->toBe(100.0);
});

it('action 3 is disabled with an explanation when the others are done', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '95', 'board_column_id' => mbDone($p)]);
    $b = mbTask($m, $this->admin);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'estimate')->set('budget_amount', '20')->call('savePanel')
        ->assertSet('block.over', 1500)->assertSee('Not available')->assertSeeHtml('disabled data-test="rebalance"');
});

it('only users who manage money get the actions: a project manager sees the message only', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '4', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin, ['estimate_hours' => 3]);
    $pm = mbMember($p, 'project_manager');
    $c = Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $t])
        ->call('openPanel', 'estimate')->set('estimate', '6')->call('savePanel')
        ->assertSet('block.over', 200)->assertSee('Nothing was saved. Ask an administrator')->assertDontSee('Raise the module budget');
    expect($t->fresh()->estimate_hours)->toBe(3.0);
    $c->call('raiseBlocked')->assertForbidden();
    expect((float) $m->fresh()->budget_hours)->toBe(4.0);
});

it('blocks the task form on create, edit and copy, and raising then creates the task', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskForm::class)
        ->set('module_id', $m->id)->set('title', 'Big one')->set('status', 'published')->set('scope', 'in')->set('budget_amount', 55)
        ->call('save')->assertSet('block.over', 1500)->assertSee('This puts Checkout $15.00 over its budget.');
    expect(Task::where('title', 'Big one')->exists())->toBeFalse();
    $c->set('blockReason', 'More scope')->call('raiseBlocked')->assertRedirect();
    expect(Task::where('title', 'Big one')->exists())->toBeTrue()->and((float) $m->fresh()->budget_amount)->toBe(115.0);

    // edit: B (unpriced) gets 99
    Livewire::actingAs($this->admin)->test(TaskForm::class, ['task' => $b])->set('budget_amount', 99)->call('save')->assertSet('block.over', 9900)->assertNoRedirect();
    expect($b->fresh()->budget_amount)->toBeNull();

    // copy of A (60) when 100 are already allocated by A=60 + Big one=55 -> over
    Livewire::actingAs($this->admin)->withQueryParams(['copy' => $a->id])->test(TaskForm::class)->call('save')->assertSet('block.over', 6000)->assertNoRedirect();
});

it('rebalance from the form saves the new task at its value', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskForm::class)
        ->set('module_id', $m->id)->set('title', 'New')->set('status', 'published')->set('budget_amount', 70)
        ->call('save')->assertSet('block.over', 3000)->call('showRebalance')->call('applyBlockedRebalance')->assertRedirect();
    expect((float) $a->fresh()->budget_amount)->toBe(30.0)->and((float) Task::where('title', 'New')->value('budget_amount'))->toBe(70.0);
});

it('a proposed add-on and an unpublished task are exempt from the cap on save', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskForm::class)
        ->set('module_id', $m->id)->set('title', 'Add-on X')->set('status', 'published')->set('scope', 'out')->set('budget_amount', 500)
        ->call('save')->assertSet('block', null)->assertRedirect();
    Livewire::actingAs($this->admin)->test(TaskForm::class)
        ->set('module_id', $m->id)->set('title', 'Draft')->set('status', 'draft')->set('budget_amount', 500)
        ->call('save')->assertSet('block', null)->assertRedirect();
});

it('refuses lowering the module budget below the task values (service and module page) with the actions', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '60']);
    mbTask($m, $this->admin, ['budget_amount' => '30']);
    expect(fn () => $m->updateBudget(['budget_amount' => '80'], $this->admin, 'cut'))->toThrow(OverBudget::class, 'This puts Checkout $10.00 over its budget.');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
    $m->updateBudget(['budget_amount' => '90'], $this->admin, 'cut'); // exactly the allocation fits

    $c = Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m->fresh()])
        ->call('openPanel', 'budget')->set('amount', '50')->set('budgetReason', 'cut more')->call('savePanel')
        ->assertSet('block.kind', 'module')->assertSet('block.over', 4000)
        ->assertSee('This puts Checkout $40.00 over its budget.')->assertSee('Create an add-on task')->assertSee('Rebalance the other tasks');
    expect((float) $m->fresh()->budget_amount)->toBe(90.0);

    // 3: rebalance the tasks to the new, lower budget, then the budget is saved
    $c->call('showRebalance')->call('applyBlockedRebalance')->assertSet('panel', null);
    expect((float) $m->fresh()->budget_amount)->toBe(50.0)->and(Task::sum('budget_amount'))->toEqualWithDelta(50.0, 0.001);
});

it('module block action 1 sets the budget to what the tasks already use', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '70']);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])
        ->call('openPanel', 'budget')->set('amount', '40')->set('budgetReason', 'trim')->call('savePanel')
        ->set('blockReason', 'Tasks need 70')->call('raiseBlocked')->assertSet('panel', null);
    expect((float) $m->fresh()->budget_amount)->toBe(70.0);
});

it('the Modules tab refuses a lowered budget and points to the module page', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '70']);
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $p])->set('tab', 'modules')
        ->set("budget.{$m->id}.amount", '10')->set("budget.{$m->id}.reason", 'x')->call('saveBudget', $m->id)
        ->assertSee('This puts Checkout $60.00 over its budget.')->assertSee('Open the module');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
});

it('a developer cannot use the block actions or the distribute helper', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $dev = mbMember($p, 'developer');
    Livewire::actingAs($dev)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('applyDistribute')->assertForbidden();
    Livewire::actingAs($dev)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('raiseBlocked')->assertForbidden();
});

// ── module panel, helper UI, money visibility ────────────────────────────────

it('shows the module panel numbers, an over amount in red, and the distribute helper to the owner', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '30', 'board_column_id' => mbDone($p)]);
    mbTask($m, $this->admin, ['budget_amount' => '10']);
    mbTask($m, $this->admin, ['estimate_hours' => 5]);
    $page = Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m]);
    $page->assertSeeHtml('data-test="budget-panel"')->assertSeeInOrder(['Budget', '$100.00', 'Allocated', '$40.00', 'Remaining', '$60.00', 'Done value', '$30.00'])
        ->assertSee('1 task unpriced')->assertSee('Distribute remaining budget by hours')->assertSeeHtml('data-test="row-unpriced"');
    $page->call('openDistribute')->assertSeeHtml('data-test="distribute-preview"')->assertSee('Share $60.00 over unpriced tasks by hours')->call('applyDistribute');
    expect((float) Task::whereNull('budget_amount')->count())->toBe(0.0)->and((float) Task::orderByDesc('id')->value('budget_amount'))->toBe(60.0);

    Task::where('budget_amount', 60)->update(['budget_amount' => '90']); // legacy data over the budget
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSee('$30.00 over')->assertSeeHtml('text-rose-600');
});

it('shows local-currency equivalents in the panel when the project currency differs', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '25']);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSee('₹8,000.00')->assertSee('₹2,000.00');
});

it('hides money from developers and shows it to the owner on the module, task and sprint pages', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $dev = mbMember($p, 'developer');
    $pm = mbMember($p, 'project_manager');
    foreach ([$dev, $pm] as $u) {
        Livewire::actingAs($u)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertDontSeeHtml('data-test="budget-panel"')->assertDontSeeHtml('row-unpriced')->assertDontSee('$60.00');
        Livewire::actingAs($u)->test(TaskShow::class, ['task' => $a])->assertDontSeeHtml('data-test="task-value"')->assertDontSeeHtml('data-test="logged-vs-estimate"')->assertDontSee('Unpriced')->assertDontSee('$60.00');
    }
    Livewire::actingAs($dev)->test(SprintPanel::class, ['project' => $p])->assertDontSeeHtml('data-test="list-value"')->assertDontSeeHtml('data-test="row-value"');
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->assertSeeHtml('data-test="list-value"')->assertSeeHtml('data-test="row-value"')->assertSee('unpriced');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $a])->assertSeeHtml('data-test="task-value"')->assertSee('$60.00');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])->assertSeeHtml('data-test="unpriced"');
});

it('a project manager editing a task never writes the amount (money is owner only)', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $pm = mbMember($p, 'project_manager');
    Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $a])->call('openPanel', 'estimate')->set('estimate', '5')->set('budget_amount', '1')->call('savePanel');
    expect((float) $a->fresh()->budget_amount)->toBe(60.0)->and($a->fresh()->estimate_hours)->toBe(5.0);
});

it('shows logged hours versus the estimate on the task page, amber over and red well over', function () {
    [$p, $m] = mbSetup();
    $t = mbTask($m, $this->admin, ['estimate_hours' => 4]);
    $log = fn (float $h) => App\Models\TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $t->id, 'user_id' => $this->admin->id, 'hours' => $h, 'worked_on' => today(), 'started_at' => now(), 'completed_at' => now()]);
    $log(2);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('Logged 2 h of 4 h estimate')->assertDontSeeHtml('text-amber-600 dark:text-amber-400" data-test="logged');
    $log(3);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('Logged 5 h of 4 h estimate')->assertSee('over by 1 h')->assertSeeHtml('font-medium text-amber-600');
    $log(2);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSeeHtml('font-medium text-rose-600');
});

// ── add-ons ─────────────────────────────────────────────────────────────────

it('a proposed add-on is exempt; accepting raises the module budget, is logged, and counts from then on', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '100']);
    $add = mbTask($m, $this->admin, ['title' => 'Extra', 'scope' => 'out', 'budget_amount' => '40']);
    $mb = new ModuleBudget($m);
    expect($mb->allocated())->toBe(10000)->and($mb->cap())->toBe(10000)->and((float) $p->fresh()->addons_amount)->toBe(0.0);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $add])->assertSee('Proposed add-on NWS-2')->assertSee('Accept add-on');

    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $add])->call('acceptAddon')->assertSee('Accepted add-on');
    $add->refresh();
    expect($add->addon_accepted_at)->not->toBeNull()->and($add->addon_accepted_by)->toBe($this->admin->id)
        ->and($mb->cap())->toBe(14000)->and($mb->allocated())->toBe(14000)->and($mb->remaining())->toBe(0);
    $change = ModuleBudgetChange::where('module_id', $m->id)->latest('id')->first();
    expect($change->reason)->toBe('Add-on: NWS-2')->and($change->old)->toBe('100.00')->and($change->new)->toBe('140.00');
    expect(Activity::where('subject_type', Task::class)->where('subject_id', $add->id)->where('event', 'addon_accepted')->exists())->toBeTrue();
    $p->refresh();
    expect((float) $p->initial_budget)->toBe(100.0)->and((float) $p->addons_amount)->toBe(40.0)->and((float) $p->final_budget)->toBe(140.0);
});

it('only the owner can accept, only once, and only an add-on with a value', function () {
    [$p, $m] = mbSetup();
    $add = mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '40']);
    Livewire::actingAs(mbMember($p, 'project_manager'))->test(TaskShow::class, ['task' => $add])->call('acceptAddon')->assertForbidden();
    expect($add->fresh()->addon_accepted_at)->toBeNull();

    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $add])->call('acceptAddon');
    $c->call('acceptAddon')->assertHasErrors('addon'); // un-accepting / double accepting is not supported
    expect((float) $m->fresh()->budget_amount)->toBe(140.0);

    $empty = mbTask($m, $this->admin, ['scope' => 'out']);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $empty])->call('acceptAddon')->assertHasErrors('addon');
    $inScope = mbTask($m, $this->admin, ['budget_amount' => '5']);
    expect(fn () => $inScope->acceptAddon($this->admin))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('an accepted add-on is not blocked by, and does not break, the cap', function () {
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '100']);
    $add = mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '25']);
    $add->acceptAddon($this->admin);
    $mb = new ModuleBudget($m);
    expect($mb->summary())->toMatchArray(['cap' => 12500, 'allocated' => 12500, 'remaining' => 0, 'proposed' => 0]);
});

// ── calculated project totals ───────────────────────────────────────────────

it('recalculates project totals when modules are created, changed, moved and deleted', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $client->id, 'name' => 'P']);
    $q = Project::create(['client_id' => $client->id, 'name' => 'Q']);
    expect((float) $p->initial_budget)->toBe(0.0)->and((float) $p->final_budget)->toBe(0.0);

    $m1 = Module::create(['project_id' => $p->id, 'name' => 'A', 'position' => 1, 'budget_amount' => '100.10']);
    $m2 = Module::create(['project_id' => $p->id, 'name' => 'B', 'position' => 2, 'budget_amount' => '50']);
    $p->refresh();
    expect((string) $p->initial_budget)->toBe('150.10')->and((string) $p->final_budget)->toBe('150.10');

    $m1->updateBudget(['budget_amount' => '200'], $this->admin, 'raise');
    expect((string) $p->fresh()->initial_budget)->toBe('250.00');

    $m2->update(['project_id' => $q->id]); // moved
    expect((string) $p->fresh()->initial_budget)->toBe('200.00')->and((string) $q->fresh()->initial_budget)->toBe('50.00');

    $m1->delete();
    expect((string) $p->fresh()->initial_budget)->toBe('0.00');
});

it('keeps the local twins consistent: initial_local + addons_local = final_local at the project rate', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '100']);
    mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '33.33'])->acceptAddon($this->admin);
    $p->refresh();
    expect((string) $p->initial_budget)->toBe('100.00')->and((string) $p->addons_amount)->toBe('33.33')->and((string) $p->final_budget)->toBe('133.33')
        ->and((string) $p->initial_budget_local)->toBe('8000.00')->and((string) $p->addons_amount_local)->toBe('2666.40')
        ->and((string) $p->final_budget_local)->toBe('10666.40')->and((float) $p->exchange_rate)->toBe(80.0);
});

it('project totals use hours x rate for an hourly client and split accepted add-ons by hours', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '10', 'hourly_rate' => '50']);
    expect((string) $p->fresh()->initial_budget)->toBe('500.00');
    $add = mbTask($m, $this->admin, ['scope' => 'out', 'estimate_hours' => 4]);
    $add->acceptAddon($this->admin);
    $p->refresh();
    expect((float) $m->fresh()->budget_hours)->toBe(14.0)->and((string) $p->initial_budget)->toBe('500.00')->and((string) $p->addons_amount)->toBe('200.00')->and((string) $p->final_budget)->toBe('700.00');
    expect(ModuleBudgetChange::where('module_id', $m->id)->where('field', 'budget_hours')->latest('id')->value('reason'))->toBe('Add-on: NWS-1');
});

it('deleting an accepted add-on recalculates the totals', function () {
    [$p, $m] = mbSetup();
    $add = mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '40']);
    $add->acceptAddon($this->admin);
    expect((float) $p->fresh()->addons_amount)->toBe(40.0);
    $add->delete();
    $p->refresh();
    expect((float) $p->addons_amount)->toBe(0.0)->and((float) $p->initial_budget)->toBe(140.0); // the module budget was raised and is left as the owner set it
});

it('the project form shows the budget fields read-only and ignores any typed values', function () {
    [$p, $m] = mbSetup();
    $c = Livewire::actingAs($this->admin)->test(ProjectForm::class, ['project' => $p])
        ->assertSeeHtml('data-test="calculated-budget"')->assertSee('Calculated from the project')->assertSeeHtml('readonly')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="initial_budget"');
    expect(property_exists(ProjectForm::class, 'initial_budget'))->toBeFalse();
    $c->set('name', 'Renamed')->call('save')->assertHasNoErrors();
    expect((float) $p->fresh()->initial_budget)->toBe(100.0);

    // the model ignores hand-set values too, and a new project starts at 0
    $new = Project::create(['client_id' => $p->client_id, 'name' => 'Fresh', 'initial_budget' => '999', 'addons_amount' => '1']);
    expect((float) $new->fresh()->initial_budget)->toBe(0.0)->and((float) $new->fresh()->final_budget)->toBe(0.0);
    Livewire::actingAs($this->admin)->test(ProjectForm::class)->set('client_id', $p->client_id)->set('name', 'Brand new')->call('save')->assertHasNoErrors();
    expect((float) Project::where('name', 'Brand new')->value('final_budget'))->toBe(0.0);
});

// ── hourly mode ─────────────────────────────────────────────────────────────

it('hourly: the cap is the budget hours, values are hours x rate, subtasks count', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '10', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin, ['estimate_hours' => 3, 'board_column_id' => mbDone($p)]);
    Subtask::create(['task_id' => $t->id, 'title' => 'S', 'estimate_hours' => 1.5]);
    mbTask($m, $this->admin, ['estimate_hours' => 2, 'testing_enabled' => true, 'testing_add_type' => 'percent', 'testing_add_value' => 50]);
    $mb = new ModuleBudget($m);
    expect($mb->hourly())->toBeTrue()->and($mb->cap())->toBe(1000)->and($mb->allocated())->toBe(450 + 300)->and($mb->remaining())->toBe(250)
        ->and($mb->valueCents($t->fresh()))->toBe(22500)->and($mb->doneValue())->toBe(22500)
        ->and($mb->wouldExceed(null, 300))->toBe(50)->and($mb->format(250))->toBe('2.5 h');
});

it('hourly: an over-hours edit is blocked with raise and add-on in hours; rebalance is explained as not applicable', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '10', 'hourly_rate' => '50']);
    mbTask($m, $this->admin, ['estimate_hours' => 8]);
    $t = mbTask($m, $this->admin, ['estimate_hours' => 1]);
    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])
        ->call('openPanel', 'estimate')->set('estimate', '4')->call('savePanel')
        ->assertSet('block.over', 200)->assertSee('This puts Checkout 2 h over its budget.')->assertSee('Raise the module budget to 12 h')
        ->assertSee('Not available: an hourly client is billed for hours worked')->assertSeeHtml('hours=4');
    expect($t->fresh()->estimate_hours)->toBe(1.0);
    $c->set('blockReason', 'More hours agreed')->call('raiseBlocked');
    expect((float) $m->fresh()->budget_hours)->toBe(12.0)->and($t->fresh()->estimate_hours)->toBe(4.0);
    expect(ModuleBudgetChange::where('module_id', $m->id)->latest('id')->first()->field)->toBe('budget_hours');
});

it('hourly: the task page shows the computed value and no amount input; the panel notes there is nothing to distribute', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '10', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin, ['estimate_hours' => 3]);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('$150.00')->assertSeeHtml('data-test="task-value"')
        ->call('openPanel', 'estimate')->assertDontSeeHtml('data-test="amount-input"')->assertSee('hourly rate');
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSeeHtml('data-test="distribute-na"')->assertSee('Done value');
});

// ── sprint billing ──────────────────────────────────────────────────────────

function mbSprint(Project $p, string $name = 'Sprint 3', ?string $from = null): Sprint
{
    $from = Illuminate\Support\Carbon::parse($from ?? '2026-11-04');

    return $p->sprints()->create(['name' => $name, 'status' => 'active', 'starts_on' => $from, 'ends_on' => $from->copy()->addDays(14)]);
}

it('completing a sprint bills its done tasks as ONE line with codes, dates and the summed value', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    $a = mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $b = mbTask($m, $this->admin, ['budget_amount' => '15.50', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $open = mbTask($m, $this->admin, ['budget_amount' => '99', 'sprint_id' => $s->id]);
    (new BillableService)->syncCompletedWork($p->client); // per-task lines exist before the sprint completes
    expect(BillableLine::where('source_type', Task::class)->count())->toBe(2);

    $s->complete('backlog');
    $lines = BillableLine::where('client_id', $p->client_id)->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->source_type)->toBe(Sprint::class)->and($lines[0]->amount_cents)->toBe(2550)
        ->and($lines[0]->description)->toBe('Sprint 3 (Nov 4 – Nov 18): completed NWS-1, NWS-2')
        ->and($lines[0]->invoice_id)->toBeNull();
    expect($open->fresh()->sprint_id)->toBeNull(); // carried-over work is not billed
});

it('the sprint line is refreshed before it is invoiced and frozen after; a late task bills on its own', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    $a = mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $s->complete('backlog');
    $svc = new BillableService;
    // another done task appears in the completed sprint before it is invoiced -> the line is refreshed
    $b = mbTask($m, $this->admin, ['budget_amount' => '5', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $svc->syncCompletedWork($p->client);
    $svc->syncCompletedWork($p->client);
    $line = BillableLine::where('source_type', Sprint::class)->firstOrFail();
    expect(BillableLine::count())->toBe(1)->and($line->amount_cents)->toBe(1500)->and($line->description)->toEndWith('completed NWS-1, NWS-2');

    $line->update(['invoice_id' => App\Models\Invoice::create(['number' => 'INV-1', 'client_id' => $p->client_id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 1500, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 1500])->id]);
    // frozen: more value in the sprint does not change it; the late task has its own line
    $late = mbTask($m, $this->admin, ['budget_amount' => '7', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $svc->syncCompletedWork($p->client);
    $line->refresh();
    expect($line->amount_cents)->toBe(1500)->and($line->description)->not->toContain('NWS-3');
    $own = BillableLine::where('source_type', Task::class)->get();
    expect($own)->toHaveCount(1)->and($own[0]->source_id)->toBe($late->id)->and($own[0]->amount_cents)->toBe(700);
    // a task never sits in two lines
    $svc->syncCompletedWork($p->client);
    expect(BillableLine::where('source_type', Task::class)->where('source_id', $a->id)->exists())->toBeFalse();
});

it('a task already invoiced on its own stays out of the sprint line', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    $a = mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $b = mbTask($m, $this->admin, ['budget_amount' => '20', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    (new BillableService)->syncCompletedWork($p->client);
    $inv = App\Models\Invoice::create(['number' => 'INV-2', 'client_id' => $p->client_id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 1000, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 1000]);
    BillableLine::where('source_id', $a->id)->update(['invoice_id' => $inv->id]);
    $s->complete('backlog');
    $sprintLine = BillableLine::where('source_type', Sprint::class)->first();
    expect($sprintLine->amount_cents)->toBe(2000)->and($sprintLine->description)->toContain('NWS-2')->and($sprintLine->description)->not->toContain('NWS-1');
    expect(BillableLine::where('source_id', $a->id)->where('source_type', Task::class)->value('invoice_id'))->toBe($inv->id); // untouched
});

it('done tasks outside a completed sprint keep their own lines, proposed add-ons are not billed', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    $a = mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $loose = mbTask($m, $this->admin, ['title' => 'Loose', 'budget_amount' => '8', 'board_column_id' => mbDone($p)]);
    $inActive = mbTask($m, $this->admin, ['title' => 'Active sprint', 'budget_amount' => '6', 'board_column_id' => mbDone($p), 'sprint_id' => mbSprint($p, 'Sprint 4', '2026-11-20')->id]);
    $proposed = mbTask($m, $this->admin, ['title' => 'Proposed', 'scope' => 'out', 'budget_amount' => '50', 'board_column_id' => mbDone($p)]);
    $s->complete('backlog');
    $lines = BillableLine::orderBy('id')->get();
    expect($lines->pluck('description')->all())->toContain('NWS-2 Loose', 'NWS-3 Active sprint')
        ->and($lines->where('source_type', Sprint::class))->toHaveCount(1)->and($lines)->toHaveCount(3)
        ->and($lines->pluck('amount_cents')->sort()->values()->all())->toBe([600, 800, 1000]);
});

it('hourly: the sprint line is hours x rate of the done tasks', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $s = mbSprint($p);
    mbTask($m, $this->admin, ['estimate_hours' => 2, 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $t = mbTask($m, $this->admin, ['estimate_hours' => 3, 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    Subtask::create(['task_id' => $t->id, 'title' => 'S', 'estimate_hours' => 1]);
    $s->complete('backlog');
    $line = BillableLine::firstOrFail();
    expect($line->amount_cents)->toBe(30000)->and($line->description)->toBe('Sprint 3 (Nov 4 – Nov 18): completed NWS-1, NWS-2');
});

it('completing a sprint freezes its planned, done and carried-over values and shows them to the owner only', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    mbTask($m, $this->admin, ['budget_amount' => '25', 'sprint_id' => $s->id]);
    mbTask($m, $this->admin);
    $dev = mbMember($p, 'developer');

    // the dialog (owner only for money)
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->call('openComplete', $s->id)
        ->assertSeeHtml('data-test="sum-planned-value"')->assertSee('$35.00')->assertSee('$10.00')->assertSee('$25.00');
    $s->complete('next');
    $s->refresh();
    expect([$s->planned_value_cents, $s->done_value_cents, $s->carried_value_cents])->toBe([3500, 1000, 2500]);
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->assertSeeHtml('data-test="past-values"')->assertSee('Planned $35.00')->assertSee('Done $10.00')->assertSee('Carried over $25.00');
    Livewire::actingAs($dev)->test(SprintPanel::class, ['project' => $p])->assertDontSeeHtml('data-test="past-values"')->assertDontSee('$35.00');
});

it('sprint plan list headers show the total task value to the owner', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    mbTask($m, $this->admin, ['budget_amount' => '10', 'sprint_id' => $s->id]);
    mbTask($m, $this->admin, ['budget_amount' => '12.5', 'sprint_id' => $s->id]);
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->assertSee('$22.50');
});

// ── seeder ──────────────────────────────────────────────────────────────────

it('the demo seeder builds the budget model on the fixed-price client', function () {
    $this->admin->update(['email' => 'admin@example.com']);
    $this->seed(Database\Seeders\DemoSeeder::class);
    $p = Project::where('name', 'Fleet Tracker')->firstOrFail();
    $maps = $p->modules()->where('name', 'Maps')->first();
    $reports = $p->modules()->where('name', 'Reports')->first();
    $mb = new ModuleBudget($maps);
    expect((float) $maps->budget_amount)->toBe(6800.0)->and($mb->summary()['unpriced'])->toBe(1)->and($mb->summary()['remaining'])->toBe(300000)
        ->and($mb->distributePreview()['ok'])->toBeTrue();
    expect(Task::whereNotNull('addon_accepted_at')->count())->toBe(1)->and($reports->tasks()->where('scope', 'out')->whereNull('addon_accepted_at')->count())->toBe(1);
    $p->refresh();
    expect((float) $p->initial_budget)->toBe(10000.0)->and((float) $p->addons_amount)->toBe(800.0)->and((float) $p->final_budget)->toBe(10800.0);
    $line = BillableLine::where('source_type', Sprint::class)->firstOrFail();
    expect($line->description)->toContain('Sprint 1')->toContain('completed FLE-1, FLE-3')->and($line->amount_cents)->toBe(450000)->and($line->invoice_id)->toBeNull();
    $this->seed(Database\Seeders\DemoSeeder::class); // idempotent
    expect(BillableLine::where('source_type', Sprint::class)->count())->toBe(1);
});

it('shows the calculated project budget cards on Details to the owner only', function () {
    [$p, $m] = mbSetup();
    $dev = mbMember($p, 'developer');
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $p->fresh()])->assertSee('Final budget')->assertSee('$100.00');
    Livewire::actingAs($dev)->test(ProjectShow::class, ['project' => $p])->assertDontSee('Final budget')->assertDontSee('$100.00');
});
