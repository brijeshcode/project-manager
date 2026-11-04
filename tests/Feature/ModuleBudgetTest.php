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


function mbLog(Task|Subtask $t, float $hours): App\Models\TimeEntry
{
    return App\Models\TimeEntry::create(['timeable_type' => $t::class, 'timeable_id' => $t->id, 'user_id' => test()->admin->id, 'hours' => $hours, 'worked_on' => today(), 'started_at' => now(), 'completed_at' => now()]);
}

/** Module 100 with A=60 priced and B unpriced; returns [project, module, A, B]. */
function mbBlockSetup(User $by): array
{
    [$p, $m] = mbSetup();
    $a = mbTask($m, $by, ['title' => 'A', 'budget_amount' => '60', 'estimate_hours' => 3]);
    $b = mbTask($m, $by, ['title' => 'B', 'estimate_hours' => 1]);

    return [$p, $m, $a, $b];
}

// ── module billing mode ─────────────────────────────────────────────────────

it('a module takes its client mode; an hourly module amount is hours x rate, kept in budget_amount and its local twin', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    expect($m->billing_mode)->toBe('hourly')->and((float) $m->budget_amount)->toBe(2000.0)->and((float) $m->budget_amount_local)->toBe(160000.0);
    $m->updateBudget(['budget_hours' => '50'], $this->admin, 'Scope grew');
    expect((float) $m->fresh()->budget_amount)->toBe(2500.0);
    expect(ModuleBudgetChange::where('module_id', $m->id)->where('field', 'budget_amount')->latest('id')->value('new'))->toBe('2500.00');
    expect(mbSetup('fixed')[1]->billing_mode)->toBe('fixed');
});

it('the module budget section asks Fixed or Hourly first and shows only the relevant labelled fields', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '1000']);
    $c = Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('openPanel', 'budget')
        ->assertSeeHtml('data-test="billing-mode"')->assertSeeHtml('for="budget-amount"')->assertSee('Budget amount')->assertSee('Reason')
        ->assertDontSeeHtml('id="budget-hours"')->assertDontSeeHtml('id="hourly-rate"');
    $c->set('billingMode', 'hourly')->assertSeeHtml('id="budget-hours"')->assertSeeHtml('id="hourly-rate"')->assertSee('Budget hours')->assertSee('Hourly rate')->assertDontSeeHtml('id="budget-amount"');

    // changing the mode needs a reason, and hours and rate go together
    $c->set('hours', '40')->set('rate', '')->call('savePanel')->assertHasErrors('rate');
    $c->set('rate', '50')->set('budgetReason', '')->call('savePanel')->assertHasErrors('budgetReason');
    expect($m->fresh()->billing_mode)->toBe('fixed');
    $c->set('budgetReason', 'Client moved to hourly')->call('savePanel')->assertHasNoErrors()->assertSet('panel', null)->assertSeeHtml('40 h &times; 50 = 2,000.00');
    $m->refresh();
    expect($m->billing_mode)->toBe('hourly')->and((float) $m->budget_amount)->toBe(2000.0);
    $log = ModuleBudgetChange::where('module_id', $m->id)->where('field', 'billing_mode')->first();
    expect($log->old)->toBe('fixed')->and($log->new)->toBe('hourly')->and($log->reason)->toBe('Client moved to hourly');
});

it('shows a live hours x rate line while editing an hourly module', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '10', 'hourly_rate' => '20']);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('openPanel', 'budget')->set('hours', '40')->set('rate', '50')->assertSeeHtml('40 h &times; 50 = 2,000.00');
});

it('switching a module to hourly values its tasks by logged hours, and back to fixed keeps those values', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '5000']);
    $t = mbTask($m, $this->admin, ['budget_amount' => '900']);
    mbLog($t, 3);
    $m->updateBudget(['billing_mode' => 'hourly', 'budget_hours' => '100', 'hourly_rate' => '40'], $this->admin, 'Now hourly');
    expect((float) $t->fresh()->budget_amount)->toBe(120.0);
    $m->fresh()->updateBudget(['billing_mode' => 'fixed', 'budget_amount' => '5000'], $this->admin, 'Back to fixed');
    mbLog($t, 2); // fixed again: logging no longer moves the value
    expect((float) $t->fresh()->budget_amount)->toBe(120.0);
});

// ── task values and the cap ──────────────────────────────────────────────────

it('a fixed task over the module amount is blocked with ONE action: raise the module amount, with an editable reason', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])
        ->call('openPanel', 'billing')->set('budget_amount', '60')->call('savePanel')
        ->assertSet('panel', 'billing')->assertSet('block.over', 2000)->assertSet('blockReason', 'Raised to fit B')
        ->assertSee('This puts Checkout $20.00 over its budget.')->assertSee('Raise the module amount to $120.00')
        ->assertDontSee('Create an add-on')->assertDontSee('Rebalance')->assertSeeHtml('for="block-reason"');
    expect($b->fresh()->budget_amount)->toBeNull();

    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])->call('openPanel', 'billing')->set('budget_amount', '60')->call('savePanel');
    $c->set('blockReason', '  ')->call('raiseBlocked')->assertHasErrors('blockReason');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
    $c->set('blockReason', 'Client approved extra scope')->call('raiseBlocked')->assertSet('block', null)->assertSet('panel', null);
    expect((float) $m->fresh()->budget_amount)->toBe(120.0)->and((float) $b->fresh()->budget_amount)->toBe(60.0);
    $change = ModuleBudgetChange::where('module_id', $m->id)->latest('id')->first();
    expect($change->field)->toBe('budget_amount')->and($change->old)->toBe('100.00')->and($change->new)->toBe('120.00')->and($change->reason)->toBe('Client approved extra scope');
});

it('lowering a task value is never blocked, and out-of-scope tasks count like any other', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin); // used 60 of 100
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $a])->call('openPanel', 'billing')->set('budget_amount', '40')->call('savePanel')->assertSet('block', null);
    mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '50']);
    expect((new ModuleBudget($m))->used())->toBe(9000);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])->call('openPanel', 'billing')->set('budget_amount', '30')->call('savePanel')->assertSet('block.over', 2000);
});

it('only users who manage money can edit values or use the block action', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $pm = mbMember($p, 'project_manager');
    Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $b])->call('openPanel', 'billing')->set('budget_amount', '60')->call('savePanel')->assertForbidden();
    expect($b->fresh()->budget_amount)->toBeNull();
    Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $b])->call('raiseBlocked')->assertForbidden();
    Livewire::actingAs(mbMember($p, 'developer'))->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('raiseBlocked')->assertForbidden();
});

it('blocks the task form on create, edit and copy, and raising then creates the task', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskForm::class)
        ->set('module_id', $m->id)->set('title', 'Big one')->set('status', 'published')->set('scope', 'in')->set('budget_amount', 55)
        ->call('save')->assertSet('block.over', 1500)->assertSee('This puts Checkout $15.00 over its budget.');
    expect(Task::where('title', 'Big one')->exists())->toBeFalse();
    $c->set('blockReason', 'More scope')->call('raiseBlocked')->assertRedirect();
    expect(Task::where('title', 'Big one')->exists())->toBeTrue()->and((float) $m->fresh()->budget_amount)->toBe(115.0);
    Livewire::actingAs($this->admin)->test(TaskForm::class, ['task' => $b])->set('budget_amount', 99)->call('save')->assertSet('block.over', 9900)->assertNoRedirect();
    expect($b->fresh()->budget_amount)->toBeNull();
    Livewire::actingAs($this->admin)->withQueryParams(['copy' => $a->id])->test(TaskForm::class)->call('save')->assertSet('block.over', 6000)->assertNoRedirect();
});

it('an unpublished task is exempt from the cap', function () {
    [$p, $m] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(TaskForm::class)->set('module_id', $m->id)->set('title', 'Draft')->set('status', 'draft')->set('budget_amount', 500)->call('save')->assertSet('block', null)->assertRedirect();
});

it('lowering a fixed module amount below the task values is blocked with the same single action', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin); // used 60
    expect(fn () => $m->updateBudget(['budget_amount' => '50'], $this->admin, 'Trim'))->toThrow(OverBudget::class);
    $c = Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->call('openPanel', 'budget')
        ->set('amount', '50')->set('budgetReason', 'Trim')->call('savePanel')
        ->assertSet('block.over', 1000)->assertSet('blockReason', 'Set to the amount the tasks already use')
        ->assertSee('This puts Checkout $10.00 over its budget.')->assertSee('Set the module amount to $60.00')->assertDontSee('Raised');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
    $c->call('raiseBlocked')->assertSet('panel', null);
    expect((float) $m->fresh()->budget_amount)->toBe(60.0);
});

it('the Modules tab refuses a lowered amount and points to the module page', function () {
    [$p, $m, $a] = mbBlockSetup($this->admin);
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $p])->set('tab', 'modules')->set("budget.{$m->id}.amount", '50')->set("budget.{$m->id}.reason", 'Trim')->call('saveBudget', $m->id)
        ->assertSee('This puts Checkout $10.00 over its budget.')->assertSee('Open the module');
    expect((float) $m->fresh()->budget_amount)->toBe(100.0);
});

// ── hourly values ────────────────────────────────────────────────────────────

it('an hourly task is worth billed hours x rate, recomputed when time entries are saved or deleted (subtasks count)', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin);
    expect((float) $t->fresh()->budget_amount)->toBe(0.0);
    $e1 = mbLog($t, 2);
    expect((float) $t->fresh()->budget_amount)->toBe(100.0);
    $e2 = mbLog($t, 3.5);
    $s = Subtask::create(['task_id' => $t->id, 'title' => 'S', 'estimate_hours' => 1]);
    $e3 = mbLog($s, 1);
    expect((float) $t->fresh()->budget_amount)->toBe(325.0)->and($t->fresh()->loggedHours())->toBe(6.5);
    $e2->update(['hours' => 1]);
    expect((float) $t->fresh()->budget_amount)->toBe(200.0);
    $e1->delete();
    $e3->delete();
    expect((float) $t->fresh()->budget_amount)->toBe(50.0);
});

it('hourly values follow the module rate and the task mode', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin);
    mbLog($t, 4);
    expect((float) $t->fresh()->budget_amount)->toBe(200.0);
    $m->fresh()->updateBudget(['hourly_rate' => '60'], $this->admin, 'Rate change');
    expect((float) $t->fresh()->budget_amount)->toBe(240.0);
    $t->fresh()->update(['hourly_rate' => '100']); // own rate
    expect((float) $t->fresh()->budget_amount)->toBe(400.0);
    $t->fresh()->update(['billing_mode' => 'fixed', 'budget_amount' => '75']);
    mbLog($t, 1);
    expect((float) $t->fresh()->budget_amount)->toBe(75.0); // fixed amounts are never rewritten
    $t->fresh()->update(['billing_mode' => null]);
    expect((float) $t->fresh()->budget_amount)->toBe(500.0);
});

it('billed hours can be adjusted by a user who manages money, with a logged reason', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin);
    mbLog($t, 12.5);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t->fresh()])->assertSeeHtml('Logged 12.5 h &middot; Billed 12.5 h &times; 50 = 625.00')
        ->call('openPanel', 'billing')->assertSeeHtml('for="billed-hours"')->assertSee('Billed hours')->assertSee('Reason')
        ->set('billedHours', '10')->call('savePanel')->assertHasErrors('billedReason')
        ->set('billedReason', 'Client pays for 10 h')->call('savePanel')->assertHasNoErrors()->assertSet('panel', null)
        ->assertSeeHtml('Logged 12.5 h &middot; Billed 10 h &times; 50 = 500.00')->assertSee('(adjusted)');
    $t->refresh();
    expect((float) $t->billable_hours)->toBe(10.0)->and((float) $t->budget_amount)->toBe(500.0);
    $act = Activity::where('subject_type', Task::class)->where('subject_id', $t->id)->where('field', 'billable_hours')->latest('id')->first();
    expect($act->new)->toBe('10')->and($act->reason)->toBe('Client pays for 10 h');
    mbLog($t, 5); // more logged time does not move adjusted hours
    expect((float) $t->fresh()->budget_amount)->toBe(500.0);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->call('openPanel', 'billing')->set('billedHours', '')->set('billedReason', 'Back to logged')->call('savePanel');
    expect((float) $t->fresh()->budget_amount)->toBe(875.0);
});

it('only a user who manages money can adjust billed hours', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin);
    $pm = mbMember($p, 'project_manager');
    Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $t])->assertDontSee('Billed')->call('openPanel', 'billing')->set('billedHours', '1')->set('billedReason', 'x')->call('savePanel')->assertForbidden();
    expect($t->fresh()->billable_hours)->toBeNull();
});

it('logging time is never blocked; the module shows the red over-budget warning instead', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '2', 'hourly_rate' => '50']); // 100
    $t = mbTask($m, $this->admin);
    mbLog($t, 5); // 250 of 100
    expect((float) $t->fresh()->budget_amount)->toBe(250.0);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSee('$150.00 over')->assertSeeHtml('data-test="bp-over"')->assertSeeHtml('text-rose-600')->assertSee('reduce billed hours or raise the module amount');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->call('openPanel', 'billing')->set('billedHours', '2')->set('billedReason', 'Cap at budget')->call('savePanel')->assertSet('block', null);
    expect((new ModuleBudget($m))->remaining())->toBe(0);
});

// ── per-task override ────────────────────────────────────────────────────────

it('a fixed task in an hourly module keeps its typed amount and shows a Fixed tag', function () {
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    Livewire::actingAs($this->admin)->test(TaskForm::class)->set('module_id', $m->id)->set('title', 'Flat fee')->set('status', 'published')
        ->set('billing_mode', 'fixed')->set('budget_amount', 300)->call('save')->assertHasNoErrors();
    $t = Task::where('title', 'Flat fee')->first();
    expect($t->billing_mode)->toBe('fixed')->and((float) $t->budget_amount)->toBe(300.0)->and($t->billedDifferently())->toBeTrue();
    mbLog($t, 5);
    expect((float) $t->fresh()->budget_amount)->toBe(300.0);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSeeHtml('data-test="row-billing"')->assertSee('Fixed')->assertSee('$300.00');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSeeHtml('data-test="billing-tag"');
    $plain = mbTask($m, $this->admin, ['title' => 'Plain']);
    expect($plain->fresh()->billedDifferently())->toBeFalse();
});

it('an hourly task in a fixed module needs a rate when neither it nor the module has one', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '1000']);
    $t = mbTask($m, $this->admin);
    $c = Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->call('openPanel', 'billing')->assertSeeHtml('data-test="billed-differently"')->set('showOverride', true)
        ->assertSeeHtml('data-test="billing-override"')->set('billingOverride', 'hourly')->call('savePanel')->assertHasErrors('taskRate');
    expect($t->fresh()->billing_mode)->toBeNull();
    $c->set('taskRate', '80')->call('savePanel')->assertHasNoErrors();
    mbLog($t, 2);
    $t->refresh();
    expect($t->billing_mode)->toBe('hourly')->and((float) $t->hourly_rate)->toBe(80.0)->and((float) $t->budget_amount)->toBe(160.0);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSeeHtml('data-test="row-billing"')->assertSee('Hourly')->assertSee('$160.00');
    // the form enforces the same rule; a module rate satisfies it
    Livewire::actingAs($this->admin)->test(TaskForm::class)->set('module_id', $m->id)->set('title', 'H')->set('status', 'published')->set('billing_mode', 'hourly')->call('save')->assertHasErrors('hourly_rate');
    $m->updateBudget(['hourly_rate' => '70'], $this->admin, '');
    Livewire::actingAs($this->admin)->test(TaskForm::class)->set('module_id', $m->id)->set('title', 'H')->set('status', 'published')->set('billing_mode', 'hourly')->call('save')->assertHasNoErrors();
    $h = Task::where('title', 'H')->first();
    mbLog($h, 3);
    expect((float) $h->fresh()->budget_amount)->toBe(210.0); // the module's rate by default
});

it('hourly values in a fixed module are never blocked, and "same as module" clears the override', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '100']);
    $t = mbTask($m, $this->admin, ['billing_mode' => 'hourly', 'hourly_rate' => '100']);
    mbLog($t, 5); // 500 of 100: no block, just over
    expect((float) $t->fresh()->budget_amount)->toBe(500.0)->and((new ModuleBudget($m))->remaining())->toBe(-40000);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->call('openPanel', 'billing')->assertSet('billingOverride', 'hourly')->set('billingOverride', '')->set('budget_amount', '20')->call('savePanel')->assertSet('block', null);
    expect($t->fresh()->billing_mode)->toBeNull()->and($t->fresh()->hourly_rate)->toBeNull()->and((float) $t->fresh()->budget_amount)->toBe(20.0);
});

// ── the panel ────────────────────────────────────────────────────────────────

it('shows the panel numbers for a mixed module: fixed 500 + fixed 700 + hourly 6 h x 50 = 1,500 used of 2,000', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '2000']);
    mbTask($m, $this->admin, ['budget_amount' => '500', 'board_column_id' => mbDone($p)]);
    mbTask($m, $this->admin, ['budget_amount' => '700']);
    $h = mbTask($m, $this->admin, ['billing_mode' => 'hourly', 'hourly_rate' => '50']);
    mbLog($h, 6);
    $s = (new ModuleBudget($m))->summary();
    expect([$s['cap'], $s['used'], $s['remaining'], $s['done']])->toBe([200000, 150000, 50000, 50000]);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSeeHtml('data-test="budget-panel"')
        ->assertSeeInOrder(['Budget', '$2,000.00', 'Used', '$1,500.00', 'Remaining', '$500.00', 'Done value', '$500.00'])->assertDontSee('Allocated')->assertDontSee('Distribute');
    mbTask($m, $this->admin, ['title' => 'No value']);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSee('1 task unpriced')->assertSeeHtml('data-test="row-unpriced"');
});

it('shows local-currency equivalents in the panel when the project currency differs', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p, $m] = mbSetup();
    mbTask($m, $this->admin, ['budget_amount' => '25']);
    Livewire::actingAs($this->admin)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertSee('₹8,000.00')->assertSee('₹2,000.00');
});

it('hourly task values get a local twin too', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p, $m] = mbSetup('hourly', ['budget_hours' => '40', 'hourly_rate' => '50']);
    $t = mbTask($m, $this->admin);
    mbLog($t, 2);
    expect((float) $t->fresh()->budget_amount_local)->toBe(8000.0);
});

it('hides money from developers and project managers and shows it to the owner on the module, task and sprint pages', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $dev = mbMember($p, 'developer');
    $pm = mbMember($p, 'project_manager');
    foreach ([$dev, $pm] as $u) {
        Livewire::actingAs($u)->test(ModuleShow::class, ['project' => $p, 'module' => $m])->assertDontSeeHtml('data-test="budget-panel"')->assertDontSeeHtml('row-unpriced')->assertDontSeeHtml('row-value')->assertDontSee('$60.00');
        Livewire::actingAs($u)->test(TaskShow::class, ['task' => $a])->assertDontSeeHtml('data-test="task-value"')->assertDontSeeHtml('data-test="logged-vs-estimate"')->assertDontSee('Unpriced')->assertDontSee('$60.00')->assertDontSee('Billed differently');
        Livewire::actingAs($u)->test(ProjectShow::class, ['project' => $p])->assertDontSee('Allocated to modules');
    }
    Livewire::actingAs($dev)->test(SprintPanel::class, ['project' => $p])->assertDontSeeHtml('data-test="list-value"')->assertDontSeeHtml('data-test="row-value"');
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->assertSeeHtml('data-test="list-value"')->assertSeeHtml('data-test="row-unpriced"');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $a])->assertSeeHtml('data-test="task-value"')->assertSee('$60.00');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $b])->assertSeeHtml('data-test="unpriced"');
});

it('a project manager editing a task never writes money (it is owner only)', function () {
    [$p, $m, $a, $b] = mbBlockSetup($this->admin);
    $pm = mbMember($p, 'project_manager');
    Livewire::actingAs($pm)->test(TaskShow::class, ['task' => $a])->call('openPanel', 'estimate')->set('estimate', '5')->set('budget_amount', '1')->call('savePanel');
    expect((float) $a->fresh()->budget_amount)->toBe(60.0)->and($a->fresh()->estimate_hours)->toBe(5.0);
    Livewire::actingAs($pm)->test(TaskForm::class, ['task' => $a])->set('budget_amount', 1)->set('billing_mode', 'hourly')->call('save');
    expect((float) $a->fresh()->budget_amount)->toBe(60.0)->and($a->fresh()->billing_mode)->toBeNull();
});

it('shows logged hours versus the estimate on the task page, amber over and red well over', function () {
    [$p, $m] = mbSetup();
    $t = mbTask($m, $this->admin, ['estimate_hours' => 4]);
    mbLog($t, 2);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('Logged 2 h of 4 h estimate')->assertDontSeeHtml('text-amber-600 dark:text-amber-400" data-test="logged');
    mbLog($t, 3);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('Logged 5 h of 4 h estimate')->assertSee('over by 1 h')->assertSeeHtml('font-medium text-amber-600');
    mbLog($t, 2);
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSeeHtml('font-medium text-rose-600');
});

// ── removed features ─────────────────────────────────────────────────────────

it('the rebalance, add-on, distribute and calculated-project-total features are really gone', function () {
    $hits = [];
    foreach ([app_path(), resource_path('views'), database_path()] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            foreach (['addon_accepted', 'acceptAddon', 'rebalance', 'Rebalance', 'distribute', 'Distribute', 'recalculateBudget', 'isAddon', 'create-addon'] as $word) {
                str_contains(file_get_contents($f->getPathname()), $word) && $hits[] = $f->getFilename().': '.$word;
            }
        }
    }
    expect($hits)->toBe([]);
    expect(Illuminate\Support\Facades\Schema::hasColumn('tasks', 'addon_accepted_at'))->toBeFalse()->and(Illuminate\Support\Facades\Schema::hasColumn('tasks', 'addon_accepted_by'))->toBeFalse();
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


it('the sprint line totals the done tasks of every kind: typed fixed, hourly by billed hours, and unpriced ones are left out and listed', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '1000']);
    $s = mbSprint($p);
    mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    $h = mbTask($m, $this->admin, ['billing_mode' => 'hourly', 'hourly_rate' => '50', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    mbLog($h, 3);
    $h->fresh()->update(['billable_hours' => 2]); // adjusted: 2 h x 50 = 100
    mbTask($m, $this->admin, ['title' => 'No value', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $p])->call('openComplete', $s->id)
        ->assertSeeHtml('data-test="sum-unpriced"')->assertSee('1 done task without a value will not be billed: NWS-3')->assertSee('$110.00');
    $s->complete('backlog');
    $line = BillableLine::where('client_id', $p->client_id)->firstOrFail();
    expect(BillableLine::count())->toBe(1)->and($line->amount_cents)->toBe(11000)->and($line->description)->toBe('Sprint 3 (Nov 4 – Nov 18): completed NWS-1, NWS-2');
    expect($s->fresh()->done_value_cents)->toBe(11000);
    Livewire::actingAs(mbMember($p, 'developer'))->test(SprintPanel::class, ['project' => $p])->assertDontSeeHtml('data-test="sum-unpriced"');
});

it('done tasks outside a completed sprint keep their own lines, priced from their own value', function () {
    [$p, $m] = mbSetup();
    $s = mbSprint($p);
    mbTask($m, $this->admin, ['budget_amount' => '10', 'board_column_id' => mbDone($p), 'sprint_id' => $s->id]);
    mbTask($m, $this->admin, ['title' => 'Loose', 'budget_amount' => '8', 'board_column_id' => mbDone($p)]);
    mbTask($m, $this->admin, ['title' => 'Active sprint', 'budget_amount' => '6', 'board_column_id' => mbDone($p), 'sprint_id' => mbSprint($p, 'Sprint 4', '2026-11-20')->id]);
    $s->complete('backlog');
    $lines = BillableLine::orderBy('id')->get();
    expect($lines->pluck('description')->all())->toContain('NWS-2 Loose', 'NWS-3 Active sprint')
        ->and($lines->where('source_type', Sprint::class))->toHaveCount(1)->and($lines->pluck('amount_cents')->sort()->values()->all())->toBe([600, 800, 1000]);
});

// ── project budget (typed again) ─────────────────────────────────────────────

it('the project initial budget and add-ons are typed, the final is their sum, and a module budget never changes them', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '700']);
    $p->update(['initial_budget' => '1000', 'addons_amount' => '250.50']);
    $m->updateBudget(['budget_amount' => '900'], $this->admin, 'x');
    mbTask($m, $this->admin, ['scope' => 'out', 'budget_amount' => '10']);
    $p->refresh();
    expect((string) $p->initial_budget)->toBe('1000.00')->and((string) $p->addons_amount)->toBe('250.50')->and((string) $p->final_budget)->toBe('1250.50');
});

it('the project form edits initial budget and add-ons (money users only) and the page shows the allocated line', function () {
    [$p, $m] = mbSetup('fixed', ['budget_amount' => '700']);
    Module::create(['project_id' => $p->id, 'name' => 'Other', 'position' => 2, 'budget_amount' => '300']);
    Livewire::actingAs($this->admin)->test(ProjectForm::class, ['project' => $p])->assertSeeHtml('data-test="project-budget"')->assertSee('Initial budget')->assertSee('Add-ons')
        ->set('initial_budget', '5,000')->set('addons_amount', '500.5')->assertSet('initial_budget', '5,000')->assertSeeHtml('value="5500.50"')->call('save')->assertHasNoErrors();
    $p->refresh();
    expect((string) $p->final_budget)->toBe('5500.50');
    Livewire::actingAs($this->admin)->test(ProjectForm::class, ['project' => $p])->set('initial_budget', 'abc')->call('save')->assertHasErrors('initial_budget');

    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $p])->assertSeeHtml('data-test="allocated-line"')->assertSee('Allocated to modules: $1,000.00 of Final $5,500.50')->assertSee('Initial budget')->assertSee('Final budget');
    $pm = mbMember($p, 'project_manager');
    Livewire::actingAs($pm)->test(ProjectForm::class, ['project' => $p])->assertDontSeeHtml('data-test="project-budget"')->set('initial_budget', '1')->call('save');
    expect((string) $p->fresh()->initial_budget)->toBe('5000.00');
});

it('keeps the project local twins consistent: initial_local + addons_local = final_local at the project rate', function () {
    (new App\Services\ExchangeRates)->setRate('USD', '80', $this->admin);
    [$p] = mbSetup();
    $p->update(['initial_budget' => '1000.10', 'addons_amount' => '250.20']);
    $p->refresh();
    expect((float) $p->exchange_rate)->toBe(80.0)->and((string) $p->initial_budget_local)->toBe('80008.00')->and((string) $p->final_budget_local)->toBe('100024.00');
});

// ── seeder ──────────────────────────────────────────────────────────────────

it('the demo seeder builds a fixed module, an hourly module with adjusted hours, and both override kinds', function () {
    $this->admin->update(['email' => 'admin@example.com']);
    $this->seed(Database\Seeders\DemoSeeder::class);
    $p = Project::where('name', 'Fleet Tracker')->firstOrFail();
    $maps = $p->modules()->where('name', 'Maps')->first();
    $reports = $p->modules()->where('name', 'Reports')->first();
    expect($maps->billingMode())->toBe('fixed')->and((float) $maps->budget_amount)->toBe(6000.0)->and($reports->billingMode())->toBe('hourly')->and((float) $reports->budget_amount)->toBe(4000.0);
    expect((new ModuleBudget($maps))->summary()['used'])->toBe(530000)->and((new ModuleBudget($reports))->summary()['used'])->toBe((int) round($reports->tasks()->sum('budget_amount') * 100));
    $export = Task::where('title', 'Fleet export API')->first();
    $score = $reports->tasks()->whereNotNull('billable_hours')->first();
    $alerts = Task::where('title', 'Driver mobile alerts')->first();
    expect($export->billing_mode)->toBe('hourly')->and((float) $export->budget_amount)->toBe(800.0)->and((float) $score->billable_hours)->toBe(16.0)->and($score->loggedHours())->toBeGreaterThanOrEqual(20.0)->and((float) $score->budget_amount)->toBe(800.0)
        ->and($alerts->billing_mode)->toBe('fixed')->and((float) $alerts->budget_amount)->toBe(1200.0);
    $p->refresh();
    expect((float) $p->initial_budget)->toBe(10000.0)->and((float) $p->addons_amount)->toBe(800.0)->and((float) $p->final_budget)->toBe(10800.0);
    $line = BillableLine::where('source_type', Sprint::class)->firstOrFail();
    expect($line->description)->toContain('Sprint 1')->toContain('completed FLE-1, FLE-2, FLE-3')->and($line->amount_cents)->toBe(520000)->and($line->invoice_id)->toBeNull();
    $this->seed(Database\Seeders\DemoSeeder::class); // idempotent
    expect(BillableLine::where('source_type', Sprint::class)->count())->toBe(1);
});

it('shows the project budget cards on Details to the owner only', function () {
    [$p, $m] = mbSetup();
    $p->update(['initial_budget' => '100']);
    $dev = mbMember($p, 'developer');
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $p->fresh()])->assertSee('Final budget')->assertSee('$100.00');
    Livewire::actingAs($dev)->test(ProjectShow::class, ['project' => $p])->assertDontSee('Final budget')->assertDontSee('$100.00');
});
