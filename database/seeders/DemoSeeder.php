<?php

namespace Database\Seeders;

use App\Enums\BillingMode;
use App\Enums\PaymentType;
use App\Enums\RecurringFrequency;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\Resource;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /**
     * Sample data so the UI has something real to show. Idempotent on the
     * demo client's name — safe to run more than once.
     */
    public function run(): void
    {
        $this->call(TaxonomySeeder::class); // types and labels exist even on a database seeded before they did
        if (! Client::where('name', 'Northwind Trading')->exists()) {
            $this->seedWorkspace();
        }

        $this->seedModuleCollaboration();
        $this->seedResource();
        $this->seedTaskCollaboration();
        $this->seedBilling();
        $this->seedBulk();
        $this->seedLargeProject();
        $this->seedTypes();
        $this->seedSprints();
        $this->seedBudgets();
    }

    /**
     * The budget model on Globex / Fleet Tracker: Maps is a FIXED module (typed task amounts, plus one task billed hourly
     * at its own rate), Reports is an HOURLY module (tasks valued by billed hours, one with hand-adjusted hours, plus one
     * task billed fixed), typed project initial budget and add-ons, and a completed sprint whose done tasks became ONE
     * billable line. Idempotent: skipped once the Maps module has a budget.
     */
    private function seedBudgets(): void
    {
        $project = Project::where('name', 'Fleet Tracker')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        $maps = $project?->modules()->where('name', 'Maps')->first();
        $reports = $project?->modules()->where('name', 'Reports')->first();
        if (! $project || ! $admin || ! $maps || ! $reports || $maps->budget_amount !== null) {
            return;
        }
        auth()->setUser($admin);
        $done = $project->columns->firstWhere('stage', 'done');
        $project->update(['initial_budget' => '10000', 'addons_amount' => '800']); // typed: final = 10,800
        $maps->updateBudget(['budget_amount' => '6000'], $admin, '');
        $reports->updateBudget(['billing_mode' => 'hourly', 'budget_hours' => '80', 'hourly_rate' => '50'], $admin, 'Billed by the hour');
        $log = fn (Task $t, float $h) => $t->timeEntries()->create(['user_id' => $admin->id, 'hours' => $h, 'worked_on' => today()->subDays(3)]);

        // Maps (fixed): two typed amounts and a task billed hourly at its own rate (10 h x 80 = 800)
        [$map, $fence] = $maps->tasks()->orderBy('position')->get();
        $map->update(['budget_amount' => '3000', 'board_column_id' => $done->id]);
        $fence->update(['budget_amount' => '1500']);
        $export = Task::create(['module_id' => $maps->id, 'creator_id' => $admin->id, 'title' => 'Fleet export API', 'status' => 'published', 'estimate_hours' => 10, 'billing_mode' => 'hourly', 'hourly_rate' => '80', 'client_visible' => true]);
        $log($export, 10);

        // Reports (hourly, 80 h x 50 = 4,000): billed hours x rate; one task has its billed hours adjusted down for the client
        [$mileage, $score] = $reports->tasks()->orderBy('position')->get();
        $mileage->update(['board_column_id' => $done->id]);
        $log($mileage, 12);
        $log($score, 20);
        $score->activityReason = 'Client agreed to pay for 16 h';
        $score->update(['billable_hours' => 16]);
        Task::create(['module_id' => $reports->id, 'creator_id' => $admin->id, 'title' => 'Driver mobile alerts', 'status' => 'published', 'estimate_hours' => 14, 'billing_mode' => 'fixed', 'budget_amount' => '1200', 'client_visible' => true]);

        // a completed sprint: its two done tasks are one billable line
        $start = now()->subDays(20)->startOfDay();
        $sprint = $project->sprints()->create(['name' => 'Sprint 1', 'status' => 'active', 'goal' => 'Maps and mileage reporting.', 'starts_on' => $start, 'ends_on' => $start->copy()->addDays(13)]);
        $map->update(['sprint_id' => $sprint->id]);
        $mileage->update(['sprint_id' => $sprint->id]);
        $fence->update(['sprint_id' => $sprint->id]);
        $sprint->complete('backlog');
        auth()->forgetUser();
    }

    /**
     * Two projects get sprints: one completed (done tasks linked), one active spanning this week (tasks across stages),
     * one planned; the rest stays in the backlog. Written straight to the table (no activity noise); idempotent per project.
     */
    private function seedSprints(): void
    {
        $monday = now()->startOfWeek();
        foreach (['Customer Portal Revamp', 'Northwind Storefront'] as $name) {
            $project = Project::where('name', $name)->first();
            if (! $project || $project->sprints()->exists()) {
                continue;
            }
            $make = fn (string $n, string $status, $from, $goal) => $project->sprints()->create([
                'name' => $n, 'status' => $status, 'goal' => $goal, 'starts_on' => $from->copy()->toDateString(), 'ends_on' => $from->copy()->addDays(6)->toDateString(),
                'completed_at' => $status === 'completed' ? $from->copy()->addDays(6) : null,
            ]);
            $past = $make('Sprint 1', 'completed', $monday->copy()->subWeek(), 'Foundations in place.');
            $active = $make('Sprint 2', 'active', $monday, 'Ship the first usable slice.');
            $planned = $make('Sprint 3', 'planned', $monday->copy()->addWeek(), 'Polish and hardening.');

            $tasks = Task::counted()->whereIn('module_id', $project->modules()->pluck('id'))->with('column')->orderBy('id')->get();
            [$done, $open] = $tasks->partition(fn ($t) => $t->column?->stage === 'done');
            $set = fn ($ts, $s) => $ts->each(fn ($t) => \Illuminate\Support\Facades\DB::table('tasks')->where('id', $t->id)->update(['sprint_id' => $s->id]));
            $set($done->take(2), $past);
            $set($done->slice(2, 1), $active); // one finished task already in the active sprint
            $set($open->values()->filter(fn ($t, $i) => $i % 3 === 0)->take(7), $active);
            $set($open->values()->filter(fn ($t, $i) => $i % 3 === 1)->take(4), $planned);
        }
    }

    /** A mix of task types: tasks still on the default "Task" type are spread over the others (written straight to the table, no activity noise). */
    private function seedTypes(): void
    {
        $default = \App\Models\TaskType::default();
        $ids = \App\Models\TaskType::orderBy('position')->pluck('id')->all();
        if (! $default || count($ids) < 2) {
            return;
        }
        Task::where('task_type_id', $default->id)->orderBy('id')->pluck('id')->each(
            fn ($id, $i) => \Illuminate\Support\Facades\DB::table('tasks')->where('id', $id)->update(['task_type_id' => $ids[$i % count($ids)]])
        );
    }

    /** One project with 7 modules, 2–5 tasks each; only two of those tasks carry subtasks. Idempotent on the project name. */
    private function seedLargeProject(): void
    {
        $client = Client::first();
        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();
        if (! $client || ! $admin || Project::where('name', 'Customer Portal Revamp')->exists()) {
            return;
        }

        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Customer Portal Revamp', 'buffer_percent' => 10,
            'start_date' => now()->subWeeks(1)->startOfWeek(), 'status' => 'active',
        ]);
        $project->setDeadline(now()->addWeeks(10)->toDateString(), $admin);
        $project->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

        $modules = [
            'Authentication' => 3, 'Dashboard' => 2, 'Billing' => 5, 'Notifications' => 4,
            'Reports' => 3, 'Admin Tools' => 2, 'Integrations' => 4,
        ];
        $withSubtasks = ['Authentication' => 0, 'Billing' => 1]; // module => index of the task that gets subtasks
        $cols = $project->columns;

        $mi = 0;
        foreach ($modules as $moduleName => $taskCount) {
            // varied priorities; Reports is completed by override, Dashboard by having every task in a done column
            $module = Module::create(['project_id' => $project->id, 'name' => $moduleName, 'position' => ++$mi, 'priority' => [2, 0, 1, 2, 1, 0, 1][$mi - 1], 'status_override' => $moduleName === 'Reports' ? 'completed' : null, 'deadline' => now()->addWeeks(2 + $mi)]);

            for ($t = 0; $t < $taskCount; $t++) {
                $task = Task::create([
                    'module_id' => $module->id, 'creator_id' => $admin->id, 'title' => "$moduleName task ".($t + 1), 'description' => '',
                    'status' => 'published', 'is_idea' => false, 'priority' => 1 + ($t % 3),
                    'estimate_hours' => 4 + $t * 2, 'scope' => 'in', 'client_visible' => true,
                    'board_column_id' => ($moduleName === 'Dashboard' ? $cols->firstWhere('stage', 'done') : $cols[($mi + $t) % $cols->count()])->id, 'position' => $t + 1,
                    'deadline' => now()->addDays(5 + $t * 4 + $mi),
                ]);

                if (($withSubtasks[$moduleName] ?? null) === $t) {
                    foreach (['Design', 'Implement', 'Test'] as $s) {
                        \App\Models\Subtask::create(['task_id' => $task->id, 'title' => "$s: {$task->title}", 'description' => '', 'estimate_hours' => 2]);
                    }
                }
            }
        }
    }

    /** Assignees, labels, a client-visible module, comments with replies and activity on the Storefront's first module. */
    private function seedModuleCollaboration(): void
    {
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        $client = User::where('email', 'client@example.com')->first();
        $module = $project?->modules()->orderBy('position')->first();
        if (! $module || ! $admin || $module->comments()->exists()) {
            return;
        }

        $dev = User::firstOrCreate(['email' => 'priya@example.com'], ['name' => 'Priya Nair', 'password' => Hash::make('password')]);
        $dev->assignRole('developer');
        $project->members()->syncWithoutDetaching([$dev->id => ['role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]]);

        auth()->setUser($admin); // so the activity log records who
        $labels = collect([['Backend', '#6366f1', 'server'], ['Payments', '#10b981', 'tag'], ['Urgent', '#ef4444', 'zap']])
            ->map(fn ($l) => \App\Models\Label::firstOrCreate(['name' => $l[0]], ['color' => $l[1], 'icon' => $l[2]]));
        $module->update(['description' => "Everything between the cart and the confirmation e-mail.

- [x] Cart totals
- [ ] Payment gateway
- [ ] Order confirmation

See the [gateway docs](https://example.com/docs).", 'client_visible' => true]);
        $module->syncAssignees([$admin->id, $dev->id]);
        $module->syncLabels($labels->take(2)->pluck('id')->all());
        $module->activityReason = 'Gateway sign-off delayed by the client.';
        $module->update(['deadline' => now()->addWeeks(5)]);
        $module->syncLabels($labels->pluck('id')->all());

        $q = $module->addComment($client ?? $admin, 'Can we also accept bank transfers at checkout?');
        $module->addComment($admin, 'Yes - we will add it behind the same gateway. Needs a day of work.', $q->id);
        $module->addComment($client ?? $admin, 'Great, thanks!', $q->id);
        $module->addComment($dev, 'Internal: gateway sandbox keys are in the Resources tab.');
        auth()->forgetUser();
    }

    /** Deadlines, assignees, labels, subtasks with descriptions, comments with replies and activity on the Storefront's tasks. */
    private function seedTaskCollaboration(): void
    {
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        $client = User::where('email', 'client@example.com')->first();
        $dev = User::where('email', 'priya@example.com')->first();
        $tasks = $project?->modules()->orderBy('position')->first()?->tasks()->orderBy('position')->get();
        if (! $tasks || $tasks->isEmpty() || ! $admin || ! $dev || $tasks->first()->comments()->exists()) {
            return;
        }

        auth()->setUser($admin); // so the activity log records who
        $labels = \App\Models\Label::whereIn('name', ['Backend', 'Payments', 'Urgent'])->get()->keyBy('name');
        $inReview = $project->columns()->where('name', 'Review')->value('id');

        foreach ($tasks as $i => $task) {
            $task->update(['deadline' => now()->addDays(4 + $i * 6), 'description' => "Work item **{$task->title}**.\n\n- [ ] Spec agreed\n- [ ] Implemented\n- [ ] Verified"]);
            $task->syncAssignees($i === 1 ? [$admin->id, $dev->id] : [$i === 0 ? $admin->id : $dev->id]);
        }
        $pay = $tasks[1] ?? $tasks->first();
        $pay->syncLabels($labels->only(['Backend', 'Payments'])->pluck('id')->all());
        $pay->syncLabels($labels->pluck('id')->all());
        $pay->activityReason = 'Gateway sign-off delayed.';
        $pay->update(['deadline' => now()->addDays(20)]);
        $pay->update(['priority' => 2, 'board_column_id' => $inReview ?? $pay->board_column_id]);

        foreach (['Gateway sandbox account' => 'Create keys at the provider and store them in **Resources**.', 'Webhook handler' => "Handle `payment.succeeded` and `payment.failed`.\n\n1. Verify signature\n2. Update the order\n3. E-mail the customer"] as $title => $body) {
            $s = \App\Models\Subtask::create(['task_id' => $pay->id, 'title' => $title, 'description' => $body, 'estimate_hours' => 3]);
            $s->syncAssignees([$dev->id]);
            $s->syncLabels([$labels['Backend']->id]);
            $s->update(['estimate_hours' => 4]);
            $s->addComment($dev, 'Started on this today.');
        }

        $q = $pay->addComment($client ?? $admin, 'Will refunds be part of this task?');
        $pay->addComment($admin, 'Not in this one, but we can add a follow-up.', $q->id);
        $pay->addComment($client ?? $admin, 'Perfect.', $q->id);
        $pay->addComment($dev, 'Internal: the provider rate-limits the sandbox.');
        auth()->forgetUser();
    }

    /** Realistic resources on the Storefront: credentials, links, one with an (encrypted) image attachment. */
    private function seedResource(): void
    {
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $project || ! $admin || $project->resources()->exists()) {
            return;
        }
        $dev = User::where('email', 'priya@example.com')->first();
        $client = User::where('email', 'client@example.com')->first();
        $label = fn ($n) => \App\Models\Label::where('name', $n)->value('id');

        auth()->setUser($admin);
        $make = function (string $title, string $value, array $labels, array $viewers, ?User $by = null) use ($project, $admin, $label) {
            $by ??= $admin;
            auth()->setUser($by);
            $r = Resource::create(['project_id' => $project->id, 'title' => $title, 'value' => $value, 'created_by' => $by->id]);
            $r->logActivity('created');
            $r->viewers()->sync(collect($viewers)->filter()->map->id->all());
            $r->labels()->sync(collect($labels)->map($label)->filter()->all());

            return $r;
        };

        $make('Production database', "**Host:** `db.northwind.example.com:5432`\n\n- **Username:** `northwind_app`\n- **Password:** `Pr0d-9fK2!vQx7`\n\nRead replica: `db-ro.northwind.example.com`. Rotate the password every quarter.", ['Production'], [$dev]);
        $make('Staging site and admin login', "- **URL:** https://staging.northwind.example.com\n- **Admin:** https://staging.northwind.example.com/admin\n- **Username:** `qa@northwind.example.com`\n- **Password:** `staging-Tr4de!`\n\nBasic-auth in front: `preview` / `northwind2026`.", ['Staging'], [$dev, $client]);
        $make('Payment gateway sandbox keys', "Publishable: `pk_test_51NwDemoPublishableKey`\n\nSecret: `sk_test_51NwDemoSecretKeyDoNotShare`\n\nWebhook signing secret: `whsec_demo_9d8f7a6b5c4d3e2f`", ['Development'], [$dev]);
        $make('Local development setup', "1. Copy `.env.example` to `.env`\n2. `composer install && npm install`\n3. Seeded login: `dev@northwind.test` / `password`\n\nMailpit runs on http://localhost:8025.", ['Development'], [$dev, $client]);

        // One with an image attachment (png), encrypted on disk like every resource file.
        $img = imagecreatetruecolor(360, 160);
        imagefill($img, 0, 0, imagecolorallocate($img, 238, 242, 255));
        foreach ([[20, 'Browser'], [135, 'Storefront'], [250, 'Database']] as [$x, $t]) {
            imagefilledrectangle($img, $x, 50, $x + 90, 100, imagecolorallocate($img, 79, 70, 229));
            imagestring($img, 3, $x + 8, 70, $t, imagecolorallocate($img, 255, 255, 255));
        }
        imageline($img, 110, 75, 135, 75, imagecolorallocate($img, 100, 116, 139));
        imageline($img, 225, 75, 250, 75, imagecolorallocate($img, 100, 116, 139));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $r = $make('Server access (SSH)', "Bastion: `ssh deploy@bastion.northwind.example.com`\n\nKey passphrase: `correct-horse-battery-staple`\n\nNetwork layout is in the diagram below.", ['Production', 'Staging'], []);
        $a = $r->attachEncrypted($png, 'network-diagram.png', 'image/png', $admin->id);
        $r->update(['value' => $r->value."\n\n![network-diagram.png]({$a->url()})"]);
        $r->viewers()->sync(array_filter([$dev?->id]));

        auth()->forgetUser();
    }

    private function seedBilling(): void
    {
        $client = Client::where('name', 'Northwind Trading')->first();
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $client || ! $project || ! $admin) {
            return;
        }

        if ($client->billing_mode === BillingMode::Fixed) {
            $client->update(['billing_mode' => BillingMode::Hourly]);
        }

        // Never overwrite an owner-set budget.
        $module = $project->modules()->orderBy('position')->first();
        if ($module && $module->hourly_rate === null && $module->budget_hours === null) {
            $module->update(['hourly_rate' => '75.00', 'budget_hours' => 40]);
        }

        RecurringCharge::firstOrCreate(
            ['client_id' => $client->id, 'description' => 'Hosting & maintenance'],
            ['amount_cents' => 12000, 'frequency' => RecurringFrequency::Monthly, 'next_due_on' => now()->addMonthNoOverflow()->startOfMonth()],
        );

        BankAccount::firstOrCreate(
            ['label' => 'Demo business account'],
            ['account_name' => 'Demo Business', 'bank_name' => 'Demo Bank', 'account_number' => '000000000000', 'ifsc_swift' => 'DEMO0000000'],
        );
    }

    private function seedWorkspace(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        if ($admin && $admin->cost_rate_type === null) {
            $admin->update(['cost_rate_type' => 'hourly', 'cost_rate_amount' => 60]);
        }

        $client = Client::create([
            'name' => 'Northwind Trading',
            'currency' => 'USD',
            'contact_name' => 'Dana Reyes',
            'contact_email' => 'dana@northwind.test',
            'contact_phone' => '+1 555 0142',
            'address' => '220 Harbor St, Seattle, WA',
            'tax_number' => 'US-99-1234567',
            'payment_terms' => 'net-30',
        ]);

        // A client-side login for testing the portal.
        $clientUser = User::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'Dana Reyes',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'client_id' => $client->id,
            ],
        );
        $clientUser->update(['client_id' => $client->id]);
        if (! $clientUser->hasRole('client')) {
            $clientUser->assignRole('client');
        }

        // Project 1 — active, with real work for live progress bars.
        $storefront = Project::create([
            'client_id' => $client->id,
            'name' => 'Northwind Storefront', 'key' => 'NWS',
            'aim' => 'Let Northwind sell online without phone orders.',
            'buffer_percent' => 15,
            'start_date' => now()->subWeeks(2)->startOfWeek(),
            'status' => 'active',
        ]);
        if ($admin) {
            $storefront->setDeadline(now()->addWeeks(5)->toDateString(), $admin);
            $storefront->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);
        }

        $module = Module::create(['project_id' => $storefront->id, 'name' => 'Checkout', 'position' => 1]);

        $specs = [
            ['Cart & line items', 'Done', 10, [true, true, true, true], 12],
            ['Payment integration', 'In progress', 16, [true, true, false, false], 20],
            ['Order confirmation email', 'Backlog', 6, [false, false], 0],
        ];

        foreach ($specs as $i => [$title, $column, $estimate, $checks, $logged]) {
            $task = Task::create([
                'module_id' => $module->id,
                'creator_id' => $admin?->id ?? $clientUser->id,
                'title' => $title,
                'description' => '',
                'status' => 'published',
                'is_idea' => false,
                'priority' => 2,
                'estimate_hours' => $estimate,
                'scope' => 'in',
                'client_visible' => true,
                'board_column_id' => $storefront->columns()->where('name', $column)->value('id'),
                'position' => $i + 1,
            ]);

            foreach ($checks as $j => $done) {
                ChecklistItem::create([
                    'checklistable_type' => Task::class,
                    'checklistable_id' => $task->id,
                    'title' => 'Step '.($j + 1),
                    'is_done' => $done,
                    'position' => $j + 1,
                ]);
            }

            if ($logged > 0 && $admin) {
                TimeEntry::create([
                    'timeable_type' => Task::class,
                    'timeable_id' => $task->id,
                    'user_id' => $admin->id,
                    'hours' => $logged,
                    'worked_on' => now()->subDays(2),
                    'started_at' => now()->subDays(2),
                    'completed_at' => now()->subDays(2)->addHours(4),
                ]);
            }
        }

        // Project 2 — a quiet draft, to show mixed states.
        Project::create([
            'client_id' => $client->id,
            'name' => 'Inventory API', 'template' => 'blank',
            'buffer_percent' => 10,
            'start_date' => now()->startOfWeek(),
            'status' => 'draft',
        ]);
    }

    /**
     * Bulk sample data (clients, projects, modules, tasks, bank accounts, invoices, payments)
     * for eyeballing the UI. Idempotent on the first client's name.
     */
    private function seedBulk(): void
    {
        if (Client::where('name', 'Acme Retail')->exists()) {
            return;
        }

        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();
        if (! $admin) {
            $this->command?->warn('No users found — run `php artisan db:seed` first.');

            return;
        }

        // Active exchange rates (units of local currency per 1 unit) so local figures work out of the box.
        // The local currency is INR unless the owner changed it in Settings.
        $local = LocalCurrency::code();
        $demoRates = ['USD' => '83.25', 'EUR' => '90.50', 'GBP' => '105.75'];
        $rates = app(ExchangeRates::class);
        foreach ($demoRates as $cur => $rate) {
            if ($cur !== $local && $rates->active($cur) === null) {
                $rates->setRate($cur, $rate, $admin);
            }
        }
        $rateFor = fn (string $cur): string => LocalCurrency::rateFor($cur) ?? '1';

        $bank = BankAccount::firstOrCreate(
            ['label' => 'Primary INR account'],
            ['account_name' => 'Demo Studio Pvt Ltd', 'bank_name' => 'HDFC Bank', 'account_number' => '50100123456789', 'ifsc_swift' => 'HDFC0001234', 'email' => 'accounts@demo.test'],
        );
        BankAccount::firstOrCreate(
            ['label' => 'USD wire account'],
            ['account_name' => 'Demo Studio Pvt Ltd', 'bank_name' => 'Citibank', 'account_number' => '9988776655', 'ifsc_swift' => 'CITIUS33'],
        );

        $data = [
            ['Acme Retail', 'USD', 'hourly', 'net-30', 'Wile Coyote', 'wile@acme.test', [
                ['Acme Web Shop', 'active', ['Catalog' => ['Product listing', 'Search & filters', 'Product reviews'], 'Orders' => ['Cart', 'Checkout', 'Order history']]],
                ['Acme Mobile App', 'active', ['Onboarding' => ['Sign-up flow', 'Push permissions'], 'Loyalty' => ['Points ledger', 'Rewards screen']]],
            ]],
            ['Globex Logistics', 'EUR', 'fixed', 'net-15', 'Hank Scorpio', 'hank@globex.test', [
                ['Fleet Tracker', 'active', ['Maps' => ['Live vehicle map', 'Geofencing'], 'Reports' => ['Daily mileage', 'Driver scorecards']]],
            ]],
            ['Initech Software', 'INR', 'hourly', 'net-45', 'Bill Lumbergh', 'bill@initech.test', [
                ['TPS Portal', 'draft', ['Reports' => ['Cover sheet template', 'Memo workflow']]],
                ['Legacy Migration', 'completed', ['Data' => ['Schema mapping', 'Cutover script', 'Verification']]],
            ]],
            ['Umbrella Health', 'GBP', 'fixed', 'net-30', 'Alice Abernathy', 'alice@umbrella.test', [
                ['Patient Portal', 'active', ['Auth' => ['SSO login', 'Consent screens'], 'Appointments' => ['Booking calendar', 'Reminders']]],
            ]],
        ];

        $n = 0;

        foreach ($data as [$name, $currency, $mode, $terms, $contact, $email, $projects]) {
            $client = Client::create([
                'name' => $name, 'currency' => $currency, 'billing_mode' => $mode, 'payment_terms' => $terms,
                'contact_name' => $contact, 'contact_email' => $email, 'contact_phone' => '+1 555 01'.sprintf('%02d', $n + 10),
                'address' => 'Demo Street '.($n + 1).', Demo City',
            ]);

            $firstProject = null;
            foreach ($projects as $p => [$projectName, $status, $modules]) {
                $project = Project::create([
                    'client_id' => $client->id, 'name' => $projectName, 'buffer_percent' => 10 + $p * 5,
                    'start_date' => now()->subWeeks(6 - $p * 2)->startOfWeek(), 'status' => $status,
                    'template' => ($n + $p) % 2 ? 'blank' : 'software',   // demo both board templates
                ]);
                $firstProject ??= $project;
                // deadlines: the first project gets an extension history; completed ones finish early
                $start = $project->start_date;
                if ($status === 'completed') {
                    $project->update(['completed_at' => $start->copy()->addWeeks(3)]);
                    $project->setDeadline($start->copy()->addWeeks(4), $admin);
                } else {
                    $project->setDeadline($start->copy()->addWeeks(7 + $p), $admin);
                    if ($n === 0 && $p === 0) {
                        $project->setDeadline($start->copy()->addWeeks(9), $admin, 'Client added the loyalty programme to scope.');
                        $project->setDeadline($start->copy()->addWeeks(10), $admin, 'Payment gateway sign-off delayed by the client.');
                        $project->setDeadline($start->copy()->addWeeks(9)->addDays(4), $admin, 'Gateway sign-off arrived early; pulled in.');
                    }
                }
                $project->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

                $mi = 0;
                foreach ($modules as $moduleName => $tasks) {
                    $module = Module::create([
                        'project_id' => $project->id, 'name' => $moduleName, 'position' => ++$mi, 'priority' => ($n + $mi) % 3,
                        'hourly_rate' => $mode === 'hourly' ? number_format(60 + $n * 15, 2, '.', '') : null,
                        'budget_hours' => $mode === 'hourly' ? 30 + $mi * 10 : null,
                        'deadline' => now()->addWeeks(2 + $mi),
                    ]);

                    foreach ($tasks as $t => $title) {
                        $cols = $project->columns;
                        $col = $cols[($n + $mi + $t) % $cols->count()];
                        $task = Task::create([
                            'module_id' => $module->id, 'creator_id' => $admin->id, 'title' => $title, 'description' => '',
                            'status' => 'published', 'is_idea' => false, 'priority' => 1 + ($t % 3),
                            'estimate_hours' => 4 + $t * 3, 'scope' => 'in', 'client_visible' => true,
                            'board_column_id' => $col->id, 'position' => $t + 1,
                            'deadline' => now()->addDays(3 + $t * 5 + $mi),
                        ]);
                        if ($col->stage !== 'not_started') {
                            TimeEntry::create([
                                'timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $admin->id,
                                'hours' => 2 + $t * 2, 'worked_on' => now()->subDays($t + 1),
                                'started_at' => now()->subDays($t + 1), 'completed_at' => now()->subDays($t + 1)->addHours(2 + $t * 2),
                            ]);
                        }
                    }
                }
            }

            // Money per client: one paid, one partly paid, one overdue and unpaid,
            // plus a pending (uninvoiced) line and a loose advance payment.
            $invoices = [
                // [days ago issued, [description, qty, unit cents], paid fraction]
                [50, ['Discovery & design', 1, 400000], 1.0],
                [25, ['Development sprint 1', 40, 7500], 0.5],
                [70, ['Hosting & maintenance', 3, 12000], 0.0],
            ];
            foreach ($invoices as $i => [$ago, [$desc, $qty, $unit], $paidFraction]) {
                $issued = now()->subDays($ago + $n)->startOfDay();
                $subtotal = $qty * $unit;
                $tax = (int) round($subtotal * 0.10);
                $invoice = Invoice::create([
                    'number' => 'INV-'.$issued->format('Ymd').'-'.sprintf('%02d', $n * 3 + $i + 1),
                    'client_id' => $client->id, 'status' => 'sent', 'issued_on' => $issued,
                    'due_on' => $issued->copy()->addDays((int) substr($terms, 4)), 'currency' => $currency,
                    'subtotal_cents' => $subtotal, 'tax_percent' => '10.00', 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax,
                    'bank_account_id' => $bank->id, 'bank_snapshot' => $bank->only(['account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email']),
                    'terms' => $terms, 'emailed_at' => $issued,
                    'exchange_rate' => $currency === $local ? '1' : $rateFor($currency),
                    'lines_snapshot' => [['description' => $desc, 'quantity' => number_format($qty, 2, '.', ''), 'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'project_id' => $firstProject->id]],
                ]);
                BillableLine::create([
                    'client_id' => $client->id, 'project_id' => $firstProject->id, 'description' => $desc, 'quantity' => $qty,
                    'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'invoice_id' => $invoice->id,
                ]);

                if ($paidFraction > 0) {
                    $paid = (int) round(($subtotal + $tax) * $paidFraction);
                    Payment::create([
                        'client_id' => $client->id, 'project_id' => $firstProject->id,
                        'amount_cents' => $paid, 'paid_on' => $issued->copy()->addDays(10),
                        'type' => PaymentType::Project, 'method' => 'bank_transfer',
                    ] + $this->localFields($paid, $currency, $local, $rateFor));
                }
            }

            BillableLine::create([
                'client_id' => $client->id, 'project_id' => $firstProject->id, 'description' => 'Extra QA pass',
                'quantity' => 6, 'unit_price_cents' => 5000, 'amount_cents' => 30000,
            ]);
            Payment::create([
                'client_id' => $client->id, 'project_id' => $firstProject->id, 'amount_cents' => 50000,
                'paid_on' => now()->subDays(5), 'type' => PaymentType::Maintenance, 'method' => 'upi', 'notes' => 'Advance, not yet invoiced',
            ] + $this->localFields(50000, $currency, $local, $rateFor));

            $n++;
        }
    }

    /** Frozen exchange rate + local amount for a payment, like the Payments form stores them. */
    private function localFields(int $cents, string $clientCurrency, string $local, callable $rateFor): array
    {
        $rate = $clientCurrency === $local ? '1' : $rateFor($clientCurrency);

        return [
            'exchange_rate' => $rate,
            'local_amount_cents' => Money::convert($cents, $rate),
            'local_currency' => $local,
        ];
    }
}
