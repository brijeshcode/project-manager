<?php

namespace Database\Seeders;

use App\Enums\BillingMode;
use App\Enums\RecurringFrequency;
use App\Models\BankAccount;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\Resource;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
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
        if (! Client::where('name', 'Northwind Trading')->exists()) {
            $this->seedWorkspace();
        }

        $this->seedResource();
        $this->seedBilling();
        $this->seedBulk();
    }

    private function seedResource(): void
    {
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $project || ! $admin) {
            return;
        }

        $resource = Resource::firstOrCreate(
            ['project_id' => $project->id, 'title' => 'Staging access'],
            ['description' => 'Demo shared resource'],
        );
        if ($resource->items()->exists()) {
            return;
        }

        $item = $resource->items()->create([
            'type' => 'text',
            'label' => 'Staging URL',
            'value' => 'https://staging.northwind.test',
            'created_by' => $admin->id,
        ]);
        $item->assignees()->sync(User::whereIn('email', ['admin@example.com', 'client@example.com'])->pluck('id'));
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
        if ($module && $module->hourly_rate_cents === null && $module->budget_hours === null) {
            $module->update(['hourly_rate_cents' => 7500, 'budget_hours' => 40]);
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
            'name' => 'Northwind Storefront',
            'buffer_percent' => 15,
            'start_date' => now()->subWeeks(2)->startOfWeek(),
            'status' => 'active',
        ]);
        if ($admin) {
            $storefront->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);
        }

        $module = Module::create(['project_id' => $storefront->id, 'name' => 'Checkout', 'position' => 1]);

        $specs = [
            ['Cart & line items', 'Done', 10, [true, true, true, true], 12],
            ['Payment integration', 'In Progress', 16, [true, true, false, false], 20],
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
                'board_column' => $column,
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
            'name' => 'Inventory API',
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

        $columns = ['Backlog', 'In Progress', 'Testing', 'Done'];
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
                ]);
                $firstProject ??= $project;
                $project->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

                $mi = 0;
                foreach ($modules as $moduleName => $tasks) {
                    $module = Module::create([
                        'project_id' => $project->id, 'name' => $moduleName, 'position' => ++$mi,
                        'hourly_rate_cents' => $mode === 'hourly' ? 6000 + $n * 1500 : null,
                        'budget_hours' => $mode === 'hourly' ? 30 + $mi * 10 : null,
                        'deadline' => now()->addWeeks(2 + $mi),
                    ]);

                    foreach ($tasks as $t => $title) {
                        $col = $columns[($n + $mi + $t) % 4];
                        $task = Task::create([
                            'module_id' => $module->id, 'creator_id' => $admin->id, 'title' => $title, 'description' => '',
                            'status' => 'published', 'is_idea' => false, 'priority' => 1 + ($t % 3),
                            'estimate_hours' => 4 + $t * 3, 'scope' => 'in', 'client_visible' => true,
                            'board_column' => $col, 'position' => $t + 1,
                        ]);
                        if ($col !== 'Backlog') {
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
                    'exchange_rate' => $currency === $local ? null : $rateFor($currency),
                    'lines_snapshot' => [['description' => $desc, 'quantity' => number_format($qty, 2, '.', ''), 'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'project_id' => $firstProject->id]],
                ]);
                BillableLine::create([
                    'client_id' => $client->id, 'project_id' => $firstProject->id, 'description' => $desc, 'quantity' => $qty,
                    'unit_price_cents' => $unit, 'amount_cents' => $subtotal, 'invoice_id' => $invoice->id,
                ]);

                if ($paidFraction > 0) {
                    $paid = (int) round(($subtotal + $tax) * $paidFraction);
                    Payment::create([
                        'client_id' => $client->id, 'project_id' => $firstProject->id, 'invoice_id' => $invoice->id,
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
