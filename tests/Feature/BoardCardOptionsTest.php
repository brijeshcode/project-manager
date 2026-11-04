<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Board;
use App\Models\BoardColumn;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

/** Project, two modules, admin, an active member, an inactive member, an outsider. */
function bcSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Alpha', 'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Beta', 'position' => 2]);
    $mk = function (string $role, bool $member = true, bool $active = true) use ($project) {
        $u = User::factory()->create();
        $u->assignRole($role);
        $member && ProjectMember::create(['project_id' => $project->id, 'user_id' => $u->id, 'role_on_project' => $role, 'access_scope' => AccessScope::All, 'active' => $active]);

        return $u;
    };
    $admin = $mk('super_admin', false);
    $member = $mk('developer');
    $inactive = $mk('developer', true, false);
    $outsider = $mk('developer', false);

    return compact('client', 'project', 'm1', 'm2', 'admin', 'member', 'inactive', 'outsider');
}

function bcTask(array $s, Module $m, string $title, array $a = []): Task
{
    return Task::create(array_merge(['module_id' => $m->id, 'creator_id' => $s['admin']->id, 'title' => $title, 'status' => TaskStatus::Published, 'priority' => 0, 'scope' => TaskScope::In, 'position' => 1], $a));
}

function bcBoard(array $s, ?User $as = null, array $q = [])
{
    return Livewire::actingAs($as ?? $s['admin'])->withQueryParams($q)->test(Board::class, ['project' => $s['project']]);
}

function bcCol(array $s, string $name): BoardColumn
{
    return $s['project']->columns()->where('name', $name)->firstOrFail();
}

it('has no left stripe, and puts the code in the top row', function () {
    $s = bcSetup();
    $t = bcTask($s, $s['m1'], 'Card', ['board_column_id' => bcCol($s, 'Backlog')->id]);
    $html = bcBoard($s)->html();

    expect($html)->not->toContain('inset-y-1.5 left-0 w-[3px]')
        ->and($html)->toMatch('/data-f="code"[^>]*>\s*<span[^>]*data-test="task-code"[^>]*>'.preg_quote($t->code(), '/').'/');
    // top row = the first flex row of the card, before the title link
    $card = Str::between($html, 'data-test="board-card"', 'data-test="card-link"');
    expect($card)->toContain('data-test="task-code"');
});

it('shows the value chip to manage-money users only, never in the developer HTML', function () {
    $s = bcSetup();
    bcTask($s, $s['m1'], 'Priced', ['board_column_id' => bcCol($s, 'Backlog')->id, 'budget_amount' => 1250, 'estimate_hours' => 4]);
    bcTask($s, $s['m1'], 'Free', ['board_column_id' => bcCol($s, 'Backlog')->id]);

    $admin = bcBoard($s)->assertSeeHtml('data-test="card-value"')->assertSee('$1,250')->html();
    expect(substr_count($admin, 'data-test="card-value"'))->toBe(1)
        ->and($admin)->toContain('data-value="125000"');

    $dev = bcBoard($s, $s['member'])->assertDontSeeHtml('card-value')->assertDontSee('1,250')->assertDontSeeHtml('data-value')->assertDontSee('1250')->assertSee('4h');
});

it('shows an hours chip when the task has an estimate', function () {
    $s = bcSetup();
    bcTask($s, $s['m1'], 'Est', ['board_column_id' => bcCol($s, 'Backlog')->id, 'estimate_hours' => 4.5]);
    bcTask($s, $s['m1'], 'None', ['board_column_id' => bcCol($s, 'Backlog')->id, 'estimate_hours' => 0]);

    $html = bcBoard($s)->html();
    expect(substr_count($html, 'data-test="card-hours"'))->toBe(1)->and($html)->toContain('4.5h');
});

it('renders the card options popover, offering Value to manage-money users only', function () {
    $s = bcSetup();
    $admin = bcBoard($s)->assertSeeHtml('data-test="card-options-pop"')->assertSeeHtml('data-test="cardopt-hours"')->assertSeeHtml('data-test="cardopt-value"');
    foreach (['type', 'priority', 'module', 'code', 'deadline', 'checklist', 'subtasks', 'client', 'labels', 'assignees'] as $f) {
        $admin->assertSeeHtml('data-test="cardopt-'.$f.'"');
    }
    bcBoard($s, $s['member'])->assertSeeHtml('data-test="cardopt-hours"')->assertDontSeeHtml('cardopt-value');
});

it('validates, authorises and persists a column limit', function () {
    $s = bcSetup();
    $col = bcCol($s, 'In progress');
    $b = bcBoard($s)->call('setLimit', $col->id, '5');
    expect($col->fresh()->wip_limit)->toBe(5);

    foreach (['0', '1000', '-3', '2.5', 'abc'] as $bad) {
        $b->call('setLimit', $col->id, $bad)->assertHasErrors(["wipLimit.{$col->id}"]);
        expect($col->fresh()->wip_limit)->toBe(5);
    }
    $b->call('setLimit', $col->id, '')->assertHasNoErrors();
    expect($col->fresh()->wip_limit)->toBeNull();

    // locked first and last columns may have limits too
    $first = $s['project']->columns()->orderBy('position')->first();
    $b->call('setLimit', $first->id, '7');
    expect($first->fresh()->wip_limit)->toBe(7);

    // another project's column is not reachable
    $other = bcSetup()['project']->columns()->first();
    expect(fn () => bcBoard($s)->call('setLimit', $other->id, '3'))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($col->fresh()->wip_limit)->toBeNull();
});

it('formats the count and its state: none, under, at, over', function () {
    $s = bcSetup();
    $col = bcCol($s, 'In progress');
    foreach (range(1, 3) as $i) {
        bcTask($s, $s['m1'], "T$i", ['board_column_id' => $col->id, 'position' => $i]);
    }
    $read = function (?int $limit) use ($s, $col) {
        $col->update(['wip_limit' => $limit]);
        $h = bcBoard($s)->html();
        $chunk = Str::after($h, 'data-column="'.$col->id.'"');
        $head = Str::beforeLast(Str::before($h, 'data-column="'.$col->id.'"'), 'data-count data-state');
        preg_match_all('/data-count data-state="(\w+)"[^>]*>([^<]*)</', Str::before($h, 'data-column="'.$col->id.'"'), $all, PREG_SET_ORDER);
        $m = end($all);

        return [$m[1], trim($m[2])];
    };

    expect($read(null))->toBe(['none', '3'])
        ->and($read(5))->toBe(['under', '3 / 5'])
        ->and($read(3))->toBe(['at', '3 / 3'])
        ->and($read(2))->toBe(['over', '3 / 2']);
});

it('shows column totals that follow the filters, value for money users only', function () {
    $s = bcSetup();
    $col = bcCol($s, 'Backlog');
    bcTask($s, $s['m1'], 'A', ['board_column_id' => $col->id, 'budget_amount' => 100, 'estimate_hours' => 4]);
    bcTask($s, $s['m1'], 'B', ['board_column_id' => $col->id, 'budget_amount' => 220, 'estimate_hours' => 10]);
    bcTask($s, $s['m2'], 'C', ['board_column_id' => $col->id, 'budget_amount' => 500, 'estimate_hours' => 1.5]);

    $totals = fn ($b) => (function () use ($b, $col) {
        preg_match_all('/data-test="column-totals"[^>]*>([^<]*)</', $b->html(), $m);

        return array_values(array_filter(array_map('trim', $m[1])));
    })();

    expect($totals(bcBoard($s)))->toBe(['$820 · 15.5h'])
        ->and($totals(bcBoard($s, null, ['modules' => [$s['m1']->id]])))->toBe(['$320 · 14h'])
        ->and($totals(bcBoard($s, $s['member'])))->toBe(['15.5h']);
    expect(bcBoard($s, $s['member'])->html())->not->toContain('$820')->not->toContain('$320');
});

it('leaves the stage templates and factories working without a limit', function () {
    $s = bcSetup();
    expect($s['project']->columns()->whereNotNull('wip_limit')->count())->toBe(0);
    bcBoard($s)->assertOk()->assertDontSeeHtml('data-limit');
});
