<?php

namespace Database\Seeders;

use App\Enums\PaymentType;
use App\Enums\RecurringFrequency;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\Task;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use App\Support\Money;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Real clients and projects for production, plus a bank account. Safe to run more than once: nothing already there
 * is duplicated or changed, and no past subscription periods are billed by the scheduler.
 * History (subscriptions, sent invoices, payments, expenses) comes from data/production-history.json when present.
 * Run: php artisan db:seed --class=ProductionSeeder
 */
class ProductionSeeder extends Seeder
{
    /** Override in tests; defaults to database/seeders/data/production-history.json. */
    public ?string $historyPath = null;

    private const CLIENTS = [
        [
            'name' => 'David Zouein', 'login' => 'david@example.com', 'country' => 'Morocco', 'phone' => '+212 661-234565',
            'company' => ['company_name' => 'Zouein Prestige', 'contact_email' => 'davidzouein@hotmail.com'],
            'projects' => [
                ['name' => 'Accounts System', 'budget' => 1500, 'completed' => '2025-03-31', 'modules' => self::ACCOUNTS_MODULES],
                ['name' => 'Luigi Darbur App', 'budget' => 2300, 'completed' => '2025-11-30', 'modules' => self::LUIGI_MODULES],
                // David paid for the development; the idea is Nick's
                ['name' => 'Wholesale Trading System', 'budget' => 2000, 'completed' => '2025-08-31', 'modules' => self::WHOLESALE_MODULES],
            ],
        ],
        [
            'name' => 'Mark Zouein', 'login' => 'mark@example.com', 'country' => 'Lebanon', 'phone' => '+961 3 737 655',
            'projects' => [
                ['name' => 'Currency Trading System', 'budget' => 1500, 'completed' => '2023-12-31', 'modules' => self::CURRENCY_MODULES, 'pending' => self::CURRENCY_PENDING],
            ],
        ],
        [
            'name' => 'Nick Zouein', 'login' => 'nick@example.com', 'country' => 'Lebanon', 'phone' => '+961 3 715 515',
            // invoices are made out to his company
            'company' => ['company_name' => 'Global-Z Est.', 'tax_number' => '3318008-601', 'contact_email' => 'Nickzouein@hotmail.com',
                'address' => 'St. Georges St., Zouein bldg, Louwayzeh, Baabda', 'city' => 'Baabda'],
            'projects' => [ 
                ['name' => 'Global Z update and issues: updates', 'budget' => 0, 'completed' => '2026-11-20', 'modules' => self::WHOLESALE_MODULES], // this has zero budget because it was created by david as wholesale trading system, and nick is upgrading and changing it by his own needs. so we add task and set price on it.
            ],
        ],
        [
            'name' => 'Smart Working', 'currency' => 'INR', 'country' => 'India', 'phone' => null,
            // contract developer, Nov 2022 to Jan 2025, fixed income with TDS; both projects built in a team
            'projects' => [
                ['name' => 'Keyloop', 'completed' => '2025-01-31'],
                ['name' => 'Stickee', 'completed' => '2025-01-31'],
            ],
        ],
        [
            // freelancer, hourly, 2020 to March 2023; projects not recorded
            'name' => 'Phonixx', 'currency' => 'INR', 'country' => null, 'phone' => null,
            'projects' => [],
        ],
        [
            'name' => 'Lokesh', 'currency' => 'INR', 'country' => 'India', 'phone' => '+91 9079072651',
            'projects' => [
                ['name' => 'Milk delivery and billing system', 'budget' => 25000, 'completed' => '2026-11-20', 'modules' => [
                    'Login' => ['Login', 'Customer login', 'Driver login'],
                    'Dashboard' => ['Dashboard', 'Delivery dashboard'],
                    'Payments' => ['Payments', 'Customer cash payment'],
                ]],
            ],
        ],
    ];

    /** Delivered modules of the wholesale trading system (module => tasks); all done. */
    private const WHOLESALE_MODULES = [
        'Auth & Users' => ['Login / logout', 'Login logs', 'User management', 'User settings'],
        'Dashboard' => ['Home page summary', 'Activity log'],
        'Customers' => ['Customers CRUD', 'Sale orders', 'Sales (invoice + PDF)', 'Proforma invoices (+ PDF)', 'Customer payment orders', 'Customer payments (+ PDF)', 'Customer return orders', 'Customer returns (+ PDF)', 'Credit / debit notes (+ PDF)', 'Customer statement (+ PDF)'],
        'Suppliers' => ['Suppliers CRUD', 'Purchases (+ PDF)', 'Purchase returns', 'Supplier payments', 'Credit / debit notes', 'Supplier statement'],
        'Items / Inventory' => ['Items CRUD', 'Price lists and bulk price update', 'Item offers', 'Item adjustments', 'Item transfers between warehouses', 'Item movements', 'Cost history', 'Price audit', 'Quantity audit'],
        'Services' => ['Services CRUD'],
        'Accounts' => ['Accounts CRUD', 'Account transfers', 'Account adjustments', 'Income transactions', 'Account statement'],
        'Expenses' => ['Expense transactions', 'Expense payments'],
        'Employees' => ['Employees CRUD', 'Salary', 'Advance loans', 'Commission targets', 'Commission calculation', 'Credit / debit notes', 'Employee statement'],
        'Vehicles' => ['Cars', 'Car refills', 'Gas stations', 'Gas station payments'],
        'Reports' => ['Sales: items sales, category sales, service sales', 'Finance: balance sheet, capital, expenses, monthly profit, VAT', 'Customer aging', 'Employee business', 'Warehouse'],
        'Setups (master data)' => ['General: company, currencies, currency rates, countries, assets, tax codes, warehouses', 'Items: brands, categories, families, groups, types, units, profit margins', 'Customers: groups, types, zones, provinces, payment terms, import', 'Suppliers: types, payment terms', 'Accounts: account types, income categories', 'Expenses: categories, tags, purchase expense subcategories', 'Employees: departments', 'Services: service types'],
        'Settings' => ['Global / system settings', 'Sale, invoice, customer payment and customer return settings', 'Supplier, purchase, purchase return, supplier note and supplier payment settings', 'Employee settings', 'Item catalog settings', 'Module lock settings', 'Syncin settings'],
        'System / Utilities' => ['Documents (uploads)', 'Backup', 'Syncin', 'Mirror', 'Clear data', 'Cache version', 'Feature flags', 'Bug lock', 'List data (dropdowns)'],
        'Landlord (multi-tenant admin)' => ['Tenant management', 'Tenant setup / onboarding', 'Tenant users', 'Tenant settings', 'Tenant currency', 'Tenant cache', 'Tenant migrations', 'Features and feature bundles', 'Command runner', 'Developer: tenant item price audit'],
    ];

    /** Delivered modules of the accounts system (module => tasks); all done. */
    private const ACCOUNTS_MODULES = [
        'Core / Platform' => ['Installer (group database, first venue, owner account)', 'Sign-in, sessions, HTTPS redirect', 'Multi-venue: switching venues, opening a venue in a new tab', 'Self-test / stress test'],
        'Dashboard' => ['KPI cards (Sales, Expenses, Net Profit, Warehouse Stock Value)', 'Sales and profit chart', 'Period filter (presets, month, date range)'],
        'Transactions (entry forms)' => ['Invoice, Customer Payment', 'Cash In, Cash Out, Bank Transfer', 'Service Payment, Purchase, Purchase Return, Expenses', 'Stock Transfer, Stock Manage, Supplier Due, Partner'],
        'History (lists, edit and delete)' => ['Invoices, Customer payments', 'Cash in/out, Service payments', 'Purchases, Purchase payments, Purchase returns', 'Bank transfers, Expenses, Expense payments, Payment groups', 'Stock transfers, Stock adjustments, Supplier dues, Partner'],
        'Reports' => ['Bank, Customer, Supplier, Expense, Expense article', 'Product sales, Product purchase, Food and cup, Invoice services', 'Stock value, Warehouse, Finance, Profit', 'Shared banks'],
        'Advanced Reports' => ['Expense to Sales, Cash Flow, Product Report', 'Product Log, Product Log Total, Stock Check', 'Finance, Annual Report'],
        'Setup (master data)' => ['Customers, Suppliers, Partners, Banks and cash, Warehouses', 'Products, External services, Expense types, Expense articles', 'Ingredient groups, Food and cup (recipes), Invoice services, Till names'],
        'Group Administration (group owner)' => ['Venues: spare databases, adding a venue from a backup or blank, copying lists from another venue', 'People: access per venue, roles', 'Roles & rights: page access, history limits, the "See profit" right', 'Shared bank accounts setup', 'Modules (turning features on and off)'],
        'Venue Administration' => ['Users, Audit log, Login history', 'Settings (business name)', 'Database tools: import, load a backup'],
        'Backups & Monitoring' => ['Daily backups to Google Drive (backup.php, gdrive.php)', 'Backups by email', 'Documents copied to Amazon S3 (s3.php)', 'Live backup download', 'Daily check of every venue (health.php)'],
        'Documents & Invoice Scanning' => ['Attachments on records (uploads)', 'Invoice reading: reads an invoice or receipt from a photo (scan.php)', 'Old documents archive (olddocs.php)'],
        'Stock Modules' => ['Stock tracking with weekly counts (stock.php, stock-track.js)', 'Shared products: bought by one venue, used by another (shared.php, shared.js)'],
        'Ops / Process' => ['Deploying to cPanel', 'Versioning and release notes (section M of the readme)', 'Git workflow for two developers (CLAUDE.md)', 'Manual (manual.pdf)'],
    ];

    /** Delivered modules of the Luigi Darbur app (module => tasks); all done. */
    private const LUIGI_MODULES = [
        'Authentication and Users' => ['Customer register/login with OTP verification and resend', 'Google and Facebook social login', 'Forgot and change password', 'Profile management and email notification preference', 'Refer and Earn', 'Admin login, forgot password and profile'],
        'Website (Frontend)' => ['Home page (sliders, banners, featured items)', 'Menu, categories, item details, search, view all', 'Boutique page', 'Language switcher with RTL/LTR direction', 'CMS pages: About, Privacy, Refund, Terms, FAQ, Gallery, Team, Blogs, Testimonials', 'Contact form and newsletter subscribe', 'Table reservation'],
        'Cart and Checkout' => ['Add to cart, update quantity, delete item', 'Open/closed check (store timings)', 'Delivery zone validation', 'Apply and remove promo codes', 'Place order and success/fail pages'],
        'Payment Gateways' => ['Payment method settings (admin)', 'PayPal, MercadoPago, ToyyibPay, PayTab, PhonePe, Mollie, Khalti, MyFatoorah', 'Payment success/fail callbacks'],
        'Customer Account' => ['Order history, details, cancel', 'Address book (CRUD)', 'Wallet: add money, transactions', 'Favorites', 'Item reviews'],
        'Order Management (Admin)' => ['Order list and details, status change', 'Assign driver', 'Invoice and print', 'Order status history', 'Reports'],
        'Catalog Management (Admin)' => ['Categories (CRUD, reorder, status)', 'Sub-categories', 'Items (CRUD, images, variations, featured, reorder)', 'Add-ons', 'Dish options', 'Item attributes', 'Item availability toggle'],
        'Branch Management' => ['Branch CRUD and branch users', 'My Branch settings and open/close toggle', 'Branch-specific items', 'Branch dashboard, reports and trends', 'PDF and Excel export'],
        'Drivers' => ['Driver CRUD, status, password', 'Driver app API: login, ongoing orders, mark delivered, online toggle, profile', 'Driver ratings'],
        'Staff and Roles' => ['Roles and permissions', 'Employees (CRUD, status)'],
        'Customers (Admin)' => ['Customer list, details, status', 'Wallet: add, deduct, change'],
        'Marketing' => ['Promo codes', 'App discount', 'Push notifications (branch-based, customer selection, send)', 'Sliders and banners (4 sections)'],
        'Ratings and Reviews' => ['Item ratings', 'Driver ratings', 'Order rating prompt and skip', 'Admin review moderation'],
        'Settings and Configuration' => ['General settings and footer features', 'Delivery zones', 'Store timings', 'Languages and translations', 'OTP configuration', 'System add-ons', 'Clear cache'],
        'Communication' => ['FCM push notifications', 'Email invoice', 'SMS log', 'WhatsApp messages'],
        'Mobile APIs' => ['Customer app API (home, menu, cart, checkout, orders, wallet, address, favorites, CMS)', 'Vendor/admin app API (login, orders, update status, assign driver, profile)', 'Branch API (list, availability toggle, user branch update)'],
        'Bookings' => ['Reservation list and status management'],
    ];

    /** Delivered modules of the currency trading system (module => tasks); all done. */
    private const CURRENCY_MODULES = [
        'Dashboards' => ['Admin, Operator and Friends dashboards', 'Dashboard trends, with chart PNG download'],
        'Setups' => ['Currencies, including the rate editor', 'Currency cases', 'Clients'],
        'Trades' => ['Single-currency trades: create, edit, view and delete', 'Trade receipt, edit history, trash, export', 'Multi-currency trades', 'Reserve trades', "Today's trades refresh"],
        'Teller Transfers' => ['Teller transfers: create, edit, view, delete and trash', 'Clear balance', 'Export', 'Teller balance refresh'],
        'Accounts' => ['Accounts, with export', 'Account transactions, with export'],
        'Ledgers' => ['Case ledger, with export', 'Currency ledger, with export'],
        'Logs' => ['Daily currency, case, teller and trade summaries', 'Daily actions', 'System profit log', 'SMS log'],
        'Notifications' => ['Direct SMS', 'WhatsApp (DLVRD)', 'SMS settings'],
        'Users & Access' => ['Users', 'Roles and permissions', 'Login history', '2FA and profile', 'Log out all users'],
        'Settings' => ['System, trade and SMS settings', 'Data pruning'],
        'Advanced / System' => ['Database backups: create, download, chunked import', 'Sync between servers: run, check, reconcile, rebuild, fix', 'System checks (case ledger)', 'Auto-fix log', 'Scheduled tasks', 'Documents'],
    ];

    /** Open items from the old (2024) todo.txt; seeded as drafts so each is checked before it goes on the board. */
    private const CURRENCY_PENDING = [
        'Bring PHPStan errors down to 0 (1,540 at max level)',
        'Print invoice',
        'Send invoice to the client over WhatsApp',
        'Setting for how many days old a transaction a user can edit',
        'Track weekly and monthly profit and show it on the dashboard',
        'Fix the dashboard showing every currency when filtering by old dates',
        'Fix manual backups not being created',
        'Year-end data minimisation (keep balances and totals only)',
        'Automatic detection of calculation issues',
        'Fix teller balance recalculation',
        'Show max 2 decimals on form totals, but calculate with full precision',
        'Remove unnecessary zero from every WHERE',
        'Fix the multi-trade edit form',
        'Raise trade rate precision to 6 decimals',
        'Add info() logging when the teller balance is refreshed',
        'Client CSV import',
        'System health: recalculate currency balances, teller transfers and trades from X days, from transaction ID X, or from the start (case balance is done)',
    ];

    public function run(): void
    {
        foreach (self::CLIENTS as $c) {
            $client = Client::firstOrCreate(['name' => $c['name']], [
                'currency' => $c['currency'] ?? 'USD',
                'contact_name' => $c['name'],
                'contact_phone' => $c['phone'],
                'country' => $c['country'],
                'notes' => 'Contact via WhatsApp or Skype.',
            ] + ($c['company'] ?? []));

            // portal login, as Clients\Show::addLogin makes it; an existing user (and its password) is left alone
            if (isset($c['login'])) {
                $login = User::firstOrCreate(['email' => $c['login']], ['name' => $c['name'], 'password' => Hash::make('password'), 'client_id' => $client->id]);
                if ($login->wasRecentlyCreated) {
                    $login->forceFill(['email_verified_at' => now()])->save();
                    $login->assignRole('client');
                }
            }

            $projects = [];
            foreach ($c['projects'] as $p) {
                $projects[$p['name']] = Project::firstOrCreate(
                    ['client_id' => $client->id, 'name' => $p['name']],
                    ['status' => isset($p['completed']) ? 'completed' : 'active', 'completed_at' => $p['completed'] ?? null, 'initial_budget' => $p['budget'] ?? 0],
                );
                $this->seedModules($projects[$p['name']], $p['modules'] ?? []);
                isset($p['pending']) && $this->seedModules($projects[$p['name']], ['Pending tasks' => $p['pending']], pending: true);
            }
        }

        BankAccount::firstOrCreate(['label' => 'PNB Nehru Palace Jaipur'], [
            'account_name' => 'Brijesh', 'bank_name' => 'Punjab National Bank', 'account_number' => '4064001500081447', 'ifsc_swift' => '',
        ]);

        if (Settings::get('number_format') === null) {
            Settings::set('number_format', 'indian', 'system');
        }

        $staff = User::whereNull('client_id')->orderBy('id')->first();
        if ($staff && ! app(ExchangeRates::class)->active('USD')) {
            app(ExchangeRates::class)->setRate('USD', '92', $staff);
        }

        $path = $this->historyPath ?? database_path('seeders/data/production-history.json');
        if (is_file($path)) {
            $this->seedHistory(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Modules and their tasks, all in the project's done column; $pending tasks are hidden drafts in the first
     * column instead. Needs a user as the tasks' creator.
     */
    private function seedModules(Project $project, array $modules, bool $pending = false): void
    {
        $creator = User::whereNull('client_id')->orderBy('id')->value('id'); // staff, not a portal login
        if (! $modules || ! $creator) {
            return;
        }
        $done = $project->columns()->where('stage', 'done')->value('id');
        foreach ($modules as $name => $tasks) {
            $module = Module::firstOrCreate(['project_id' => $project->id, 'name' => $name], ['position' => $project->modules()->count() + 1, 'client_visible' => ! $pending]);
            foreach ($tasks as $title) {
                Task::firstOrCreate(['module_id' => $module->id, 'title' => $title], $pending
                    ? ['creator_id' => $creator, 'status' => 'draft', 'client_visible' => false]
                    // delivered long ago: already accepted, so not on the client's approval list
                    : ['creator_id' => $creator, 'status' => 'published', 'client_visible' => true, 'board_column_id' => $done, 'client_approval' => 'approved']);
            }
        }
    }

    /** Subscriptions, sent invoices with their lines, and payments. Records whose client is missing are skipped. */
    private function seedHistory(array $data): void
    {
        $n = ['subscriptions' => 0, 'invoices' => 0, 'payments' => 0, 'skipped' => 0];
        $clients = Client::pluck('id', 'name');
        $currencies = Client::get()->pluck('currency', 'name');
        // the client's own project first; else one of another client's (e.g. Nick's project paid by David)
        $project = fn (int $clientId, ?string $name) => $name ? Project::where('name', $name)->orderByRaw('client_id = ? desc', [$clientId])->value('id') : null;
        $month = fn (string $ym) => Carbon::createFromFormat('!Y-m', $ym);
        // an invoice takes the rate of the client's payment nearest its issue date; no payments -> current rate
        $paidRates = collect($data['payments'] ?? [])->filter(fn ($p) => isset($p['exchange_rate']));
        $rateFor = fn (string $client, string $date) => $paidRates
            ->filter(fn ($p) => $p['client'] === $client)
            ->sortBy(fn ($p) => abs(strtotime($p['paid_on']) - strtotime($date)))
            ->first()['exchange_rate'] ?? LocalCurrency::rateFor($currencies[$client] ?? 'USD');

        foreach ($data['subscriptions'] ?? [] as $s) {
            $cid = $clients[$s['client']] ?? null;
            if (! $cid || RecurringCharge::where('client_id', $cid)->where('description', $s['description'])->exists()) {
                $n['skipped']++;

                continue;
            }
            $start = $month($s['first_period']);
            $charge = RecurringCharge::create([
                'client_id' => $cid, 'project_id' => $project($cid, $s['project'] ?? null), 'description' => $s['description'],
                'amount_cents' => Money::toCents((string) $s['amount']), 'frequency' => RecurringFrequency::Monthly, 'due_day' => 1,
                'next_due_on' => $start, 'active' => false, 'pass_through' => (bool) ($s['pass_through'] ?? false),
            ]);
            if ($s['active']) {
                // older ones were billed by hand (past months not billed); one starting this month or later bills from its start
                $charge->startApproved($start->gte(today()->startOfMonth()), today());
            } else {
                $end = $month($s['last_period'])->endOfMonth();
                $charge->forceFill(['client_state' => RecurringCharge::ENDED, 'client_approved_at' => $start, 'ended_at' => $end, 'ends_on' => $end->toDateString(), 'next_due_on' => $end->copy()->addDay()->startOfDay()])->save();
            }
            $n['subscriptions']++;
        }

        foreach ($data['invoices'] ?? [] as $inv) {
            $cid = $clients[$inv['client']] ?? null;
            if (! $cid || Invoice::where('number', $inv['number'])->exists()) {
                $n['skipped']++;

                continue;
            }
            DB::transaction(function () use ($inv, $cid, $project, $rateFor, $currencies) {
                $rate = $inv['exchange_rate'] ?? $rateFor($inv['client'], $inv['issued_on']);
                $rate = $rate === null ? null : number_format((float) $rate, 6, '.', '');
                $lines = array_map(fn ($l) => $l + [
                    'cents' => Money::toCents((string) $l['amount']),
                    'unit_cents' => Money::toCents((string) $l['unit_price']),
                    'project_id' => $project($cid, $l['project'] ?? null),
                    'charge_id' => ($l['subscription'] ?? null) ? RecurringCharge::where('client_id', $cid)->where('description', $l['subscription'])->value('id') : null,
                ], $inv['lines']);
                $subtotal = array_sum(array_column($lines, 'cents'));
                $invoice = Invoice::create([
                    'number' => $inv['number'], 'client_id' => $cid, 'status' => 'sent', 'issued_on' => $inv['issued_on'], 'due_on' => null,
                    'currency' => $currencies[$inv['client']] ?? 'USD', 'deductions_cents' => Money::toCents((string) ($inv['deductions'] ?? 0)), 'subtotal_cents' => $subtotal, 'tax_percent' => '0.00', 'tax_cents' => 0, 'total_cents' => $subtotal,
                    'terms' => $inv['terms'] ?? null, 'emailed_at' => $inv['issued_on'], 'exchange_rate' => $rate, 'for_month' => $inv['period'] ?? null,
                    'show_quantity' => collect($lines)->contains(fn ($l) => (float) $l['qty'] != 1.0),
                    'lines_snapshot' => array_map(fn ($l) => ['description' => $l['description'], 'quantity' => number_format((float) $l['qty'], 2, '.', ''), 'unit_price_cents' => $l['unit_cents'], 'amount_cents' => $l['cents'], 'project_id' => $l['project_id']], $lines),
                ]);
                foreach ($lines as $l) {
                    $period = $l['period'] ?? $inv['period'];
                    // one line per subscription and period (DB unique): a second one for the same month stays unlinked
                    $link = $l['charge_id'] && ! BillableLine::where('recurring_charge_id', $l['charge_id'])->where('period', $period)->exists();
                    BillableLine::create([
                        'client_id' => $cid, 'project_id' => $l['project_id'], 'invoice_id' => $invoice->id, 'description' => $l['description'],
                        'recurring_charge_id' => $link ? $l['charge_id'] : null, 'period' => $link ? $period : null,
                        'quantity' => $l['qty'], 'unit_price_cents' => $l['unit_cents'], 'amount_cents' => $l['cents'],
                        'pass_through' => (bool) ($l['pass_through'] ?? false), 'exchange_rate' => $rate,
                    ]);
                }
            });
            $n['invoices']++;
        }

        $seen = []; // same-day transfers of the same amount are real (e.g. 408 + 408): the Nth one is new only if fewer than N exist
        foreach ($data['payments'] ?? [] as $p) {
            $cid = $clients[$p['client']] ?? null;
            $cents = Money::toCents((string) $p['amount']);
            $key = "$cid|{$p['paid_on']}|$cents";
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if (! $cid || Payment::where('client_id', $cid)->whereDate('paid_on', $p['paid_on'])->where('amount_cents', $cents)->count() >= $seen[$key]) {
                $n['skipped']++;

                continue;
            }
            Payment::create([
                'client_id' => $cid, 'amount_cents' => $cents, 'paid_on' => $p['paid_on'], 'type' => PaymentType::Maintenance,
                'method' => $p['method'] ?? null, 'notes' => $p['notes'] ?? null, 'currency' => $currencies[$p['client']] ?? 'USD', 'deductions_cents' => Money::toCents((string) ($p['deductions'] ?? 0)), 'documents_visible_to_client' => false,
                // the notes say which month it paid for: "...; for Jan 2024" ("for Mar or Apr 2025" takes the first)
                'for_month' => preg_match('/for (\w{3})(?: or \w{3})? (\d{4})/', $p['notes'] ?? '', $m) ? Carbon::parse("1 $m[1] $m[2]")->format('Y-m') : null,
                // the rate and INR actually received, as printed on the transfer receipt
                'exchange_rate' => $p['exchange_rate'] ?? null,
                'local_amount_cents' => isset($p['local_amount']) ? Money::toCents((string) $p['local_amount']) : null,
                'local_currency' => $p['local_currency'] ?? null,
            ]);
            $n['payments']++;
        }

        // money paid out (developer fees etc.), each fully paid on the day; admin only
        $n['expenses'] = 0;
        foreach ($data['expenses'] ?? [] as $e) {
            $cid = isset($e['client']) ? ($clients[$e['client']] ?? null) : null;
            $cents = Money::toCents((string) $e['amount']);
            if (Expense::where('description', $e['description'])->whereDate('incurred_on', $e['paid_on'])->where('amount_cents', $cents)->exists()) {
                $n['skipped']++;

                continue;
            }
            $expense = Expense::create([
                'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => $e['category']])->id, 'description' => $e['description'], 'payee' => $e['payee'] ?? null, 'currency' => $e['currency'] ?? 'USD',
                'amount_cents' => $cents, 'exchange_rate' => $e['exchange_rate'] ?? null, 'incurred_on' => $e['paid_on'], 'client_id' => $cid, 'project_id' => $cid ? $project($cid, $e['project'] ?? null) : null, 'notes' => $e['notes'] ?? null,
            ]);
            $expense->payments()->create([
                'paid_on' => $e['paid_on'], 'amount_cents' => $cents, 'method' => $e['method'] ?? null, 'exchange_rate' => $e['exchange_rate'] ?? null,
                'local_amount_cents' => isset($e['local_amount']) ? Money::toCents((string) $e['local_amount']) : null, 'local_currency' => $e['local_currency'] ?? null,
            ]);
            $n['expenses']++;
        }

        $this->command?->info("History: {$n['subscriptions']} subscriptions, {$n['invoices']} invoices, {$n['payments']} payments, {$n['expenses']} expenses created; {$n['skipped']} skipped.");
    }
}
