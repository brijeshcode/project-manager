<?php

use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Economics;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ProjectEconomics;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'ErCo', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->p = Project::create(['client_id' => $this->client->id, 'name' => 'Er Project', 'key' => 'ERP', 'status' => 'active']);
    $this->done = $this->p->columns->firstWhere('stage', 'done')->id;
    $this->a = Module::create(['project_id' => $this->p->id, 'name' => 'Alpha Done', 'position' => 1, 'client_visible' => true, 'budget_amount' => '500']);
    $this->b = Module::create(['project_id' => $this->p->id, 'name' => 'Beta Open', 'position' => 2, 'client_visible' => true, 'budget_amount' => '100']);
});

function erTask(Module $m, User $by, array $a = []): Task
{
    return Task::create($a + ['module_id' => $m->id, 'creator_id' => $by->id, 'title' => 'T', 'status' => 'published', 'client_visible' => true]);
}

function erInvoice(Project $p, Task $t, string $status = 'sent'): void
{
    $l = BillableLine::create(['client_id' => $p->client_id, 'project_id' => $p->id, 'source_type' => Task::class, 'source_id' => $t->id,
        'description' => $t->code(), 'quantity' => 1, 'unit_price_cents' => 1, 'amount_cents' => (int) ($t->budget_amount * 100)]);
    app(InvoiceService::class)->create($p->client, [$l->id])->forceFill(['status' => $status])->save();
}

it('puts completed modules and completed tasks last, under a divider', function () {
    erTask($this->a, $this->admin, ['title' => 'Alpha Task', 'budget_amount' => '300', 'board_column_id' => $this->done]);
    erTask($this->b, $this->admin, ['title' => 'Finished Beta', 'budget_amount' => '40', 'board_column_id' => $this->done]);
    erTask($this->b, $this->admin, ['title' => 'Open Beta', 'budget_amount' => '50']);

    Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->p, 'embedded' => true])
        ->assertSeeInOrder(['Beta Open', 'Working', 'Open Beta', 'Finished Beta', 'Completed', 'Alpha Done', 'Alpha Task'])
        ->assertSeeHtml('data-test="budget-completed-divider"');
});

it('shows totals in the collapsed module header and the billing state', function () {
    $t = erTask($this->a, $this->admin, ['title' => 'Billed Task', 'budget_amount' => '300', 'board_column_id' => $this->done]);
    erTask($this->b, $this->admin, ['title' => 'Ready Task', 'budget_amount' => '80', 'board_column_id' => $this->done]);
    erTask($this->b, $this->admin, ['title' => 'Todo Task', 'budget_amount' => '20']);
    erInvoice($this->p, $t);

    Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->p, 'embedded' => true])
        ->assertSeeInOrder(['Ready to invoice', '$80.00', '1 done task not on an invoice'])
        ->assertSeeInOrder(['Beta Open', 'Fixed', 'Not invoiced', 'Budget', '$100.00', 'Tasks', '$100.00', 'Done', '$80.00', 'Invoiced', '$0.00', 'Ready to invoice', '$80.00', 'Todo Task', 'Ready Task', 'Ready to invoice'])
        ->assertSeeInOrder(['Alpha Done', 'Invoiced', 'Budget', '$500.00', 'Tasks', '$300.00', 'Invoiced', '$300.00', 'Billed Task', 'Invoiced'])
        ->assertSee('Outstanding $300.00');

    Payment::create(['client_id' => $this->client->id, 'project_id' => $this->p->id, 'amount_cents' => 30000, 'paid_on' => today()->toDateString(), 'type' => 'project']);
    Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->p, 'embedded' => true])
        ->assertSeeInOrder(['Alpha Done', 'Paid', 'Billed Task', 'Paid'])->assertSee('All invoices paid');
});

it('reports draft, sent and sprint-line invoicing per task', function () {
    $d = erTask($this->a, $this->admin, ['title' => 'Drafted', 'budget_amount' => '10', 'board_column_id' => $this->done]);
    $s = erTask($this->a, $this->admin, ['title' => 'Sent', 'budget_amount' => '10', 'board_column_id' => $this->done]);
    $sprint = Sprint::create(['project_id' => $this->p->id, 'name' => 'Sprint 1', 'starts_on' => today(), 'ends_on' => today()->addDays(7)]);
    $sp = erTask($this->a, $this->admin, ['title' => 'In sprint', 'budget_amount' => '10', 'board_column_id' => $this->done, 'sprint_id' => $sprint->id]);
    $other = erTask($this->a, $this->admin, ['title' => 'Later', 'budget_amount' => '10', 'board_column_id' => $this->done, 'sprint_id' => $sprint->id]);
    erInvoice($this->p, $d, 'draft');
    erInvoice($this->p, $s);
    $l = BillableLine::create(['client_id' => $this->client->id, 'project_id' => $this->p->id, 'source_type' => Sprint::class, 'source_id' => $sprint->id,
        'description' => 'Sprint 1: completed '.$sp->code(), 'quantity' => 1, 'unit_price_cents' => 1000, 'amount_cents' => 1000]);
    app(InvoiceService::class)->create($this->client, [$l->id]);

    $pd = erTask($this->a, $this->admin, ['title' => 'Sent one', 'budget_amount' => '10', 'board_column_id' => $this->done]);
    erInvoice($this->p, $pd);

    $tasks = Task::whereIn('id', [$d->id, $s->id, $sp->id, $other->id, $pd->id])->get();
    expect(ProjectEconomics::invoicedTasks($this->p, $tasks))->toBe([$d->id => 'draft', $s->id => 'sent', $sp->id => 'sent', $pd->id => 'sent']); // no per-invoice paid: payments settle the client balance
});

it('keeps billing and cost out of the client budget tab but shows status and order', function () {
    erTask($this->a, $this->admin, ['title' => 'Alpha Task', 'budget_amount' => '300', 'board_column_id' => $this->done]);
    erTask($this->b, $this->admin, ['title' => 'Open Beta', 'budget_amount' => '50']);
    $cu = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');

    Livewire::actingAs($cu)->test(ProjectView::class, ['project' => $this->p])->set('tab', 'budget')
        ->assertSeeInOrder(['Beta Open', 'Pending', 'Completed', 'Alpha Done', 'Completed'])
        ->assertDontSee('Ready to invoice')->assertDontSee('Not invoiced')->assertDontSee('Over by')
        ->assertDontSeeHtml('data-test="budget-billing"')->assertDontSeeHtml('data-test="budget-mode"')
        ->assertDontSee('Cost of logged')->assertDontSee('Profit');
});

it('forbids economics to users without manage-money', function () {
    $pm = User::factory()->create()->assignRole('project_manager');
    $this->p->members()->attach($pm->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

    Livewire::actingAs($pm)->test(Economics::class, ['project' => $this->p])->assertForbidden();
    $this->actingAs($pm)->get(route('projects.economics', $this->p))->assertForbidden();
});
