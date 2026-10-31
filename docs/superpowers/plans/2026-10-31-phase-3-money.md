# Phase 3 — Money Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Invoice clients from pending work and recurring charges, keep a per-client balance driven only by invoices and payments, email PDF invoices, and show the owner per-project earnings analysis.

**Architecture:** Everything billable is a `BillableLine` (snapshot of description/qty/price). A line is *pending* while `invoice_id` is null. Creating an invoice (`InvoiceService::create`) attaches chosen lines to a new `Invoice` — the only event that moves a client balance. Balance = payments − non-void invoice totals (derived, no ledger table). Voiding detaches the lines (back to pending). Owner-only screens are guarded by one gate `manage-money`; client screens re-scope every query by `auth()->user()->client_id`. PDF and email body render one shared Blade partial.

**Tech Stack:** Laravel 12, Livewire 3, Pest, SQLite dev, queued Mail (`QUEUE_CONNECTION=database`), `barryvdh/laravel-dompdf` (the one new dependency, approved by the spec).

**Spec:** [docs/superpowers/specs/2026-10-31-phase-3-money-design.md](../specs/2026-10-31-phase-3-money-design.md)

## Global Constraints

- Branch `phase-3` (exists, off `main`). Commit per task. **No `Co-Authored-By` trailer** (owner's rule).
- **Money is integer minor units** (`*_cents` bigInteger columns), never float. One helper `App\Support\Money` for formatting/parsing. Tax = `round(subtotal_cents × percent / 100)` half-up. Currency is the client's (`clients.currency`); INR is a display line only (manual rate on the invoice, no API fetch).
- Owner-only = `Gate::allows('manage-money')` (true only for `super_admin`). Clients never see cost rates, hours-cost, profit, or anyone else's data.
- **Balance changes only on invoice creation, void, or payment.** Invoices are immutable after creation (no edit); lines are snapshots; removed/unticked lines stay pending.
- Invoice number `INV-YYYYMMDD-NN` (per-day counter, one sequence across clients), unique-indexed, never reused (voided numbers stay used).
- Invoice status stored as `sent` | `void`; "paid" is derived (sum of payments linked to the invoice ≥ total).
- Business expenses / business-wide P&L, payment gateways, credit notes, reminders are **out of scope** — build no tables for them.
- Tests: Pest + `RefreshDatabase`, `$this->seed(RoleSeeder::class)` in `beforeEach`, `Mail::fake()` for mail. Run via bash with a redirect: `php artisan test --filter=X > out.txt 2>&1; echo $?`, read then delete `out.txt`. Use PowerShell for composer/npm only if bash fails; no npx in Git Bash.
- Follow Phase 2 conventions: Livewire components in `app/Livewire/<Area>/`, views in `resources/views/livewire/<area>/`, `x-ui.*` kit, escaped output, every action authorizes and re-scopes ids (`findOrFail` → 404).

## Review Focus

1. Two invoices created the same day (and concurrently) → distinct, correctly counted numbers (Task 6).
2. Void → lines return to pending, balance reverses; voiding twice is a no-op (Task 6).
3. Client A asking for client B's invoice page, PDF, or payments → 404; a client never sees cost/profit (Tasks 8, 10, 11).
4. Recurring command run twice in a day, or after several missed months → no duplicate lines, missed periods caught up once each (Task 4).
5. Invoice email dispatched twice (retry) → sent once; client without contact email → invoice still created, no crash, UI flags it (Task 8).

---

### Task 1: Billing config — money helper, gate, billing mode, module budgets + change log

**Files:**
- Create: `app/Support/Money.php`, `app/Enums/BillingMode.php`, `app/Models/ModuleBudgetChange.php`, `database/migrations/2026_11_01_000001_add_billing_config.php`
- Modify: `app/Models/Client.php` (`billing_mode` fillable + cast), `app/Models/Module.php`, `app/Providers/AppServiceProvider.php` (gate), `resources/views/livewire/clients/form.blade.php` + `app/Livewire/Clients/Form.php` (billing mode select), `app/Livewire/Projects/Show.php` + `show.blade.php` (budget edit per module, only when `manage-money`)
- Test: `tests/Feature/BillingConfigTest.php`, `tests/Unit/MoneyTest.php`

**Interfaces:**
- Produces: `Money::format(int $cents, string $currency): string` (e.g. `$1,980.00`); `Money::toCents(string|int|float $amount): int` (half-up); `BillingMode::{Fixed='fixed',Hourly='hourly'}`; `clients.billing_mode` (default `fixed`); `modules.budget_hours decimal(8,2)?`, `hourly_rate_cents bigInt?`, `budget_amount_cents bigInt?`, `deadline date?`; `Module::budgetCents(): ?int` (hourly client → hours×rate; fixed → amount); `Module::updateBudget(array $attrs, User $by, string $reason): void` — writes one `module_budget_changes` row per changed field (`module_id,user_id,field,old,new,reason,created_at`); gate `manage-money`.

- [ ] **Step 1: Write failing tests**: `Money::toCents('19.995')===2000`, `format(198000,'USD')==='$1,980.00'`; `budgetCents` hourly 10h×5000 → 50000, fixed amount 100000 → 100000, missing → null; `updateBudget` raising hours 10→15 with reason logs one row (old 10, new 15, reason, user); non-super-admin `Gate::allows('manage-money')` false, super admin true; a developer calling the Project page's budget action → 403.
- [ ] **Step 2: Run** `--filter="BillingConfigTest|MoneyTest"` → FAIL.
- [ ] **Step 3: Implement** per Interfaces; gate defined as `Gate::define('manage-money', fn ($u) => $u->hasRole('super_admin'))`; budget form fields only rendered/handled under the gate; reason required when changing an existing non-null budget.
- [ ] **Step 4: Run** → PASS; also run `ProjectWorkspaceTest`, `ClientTest`.
- [ ] **Step 5: Commit** `feat: billing config — money helper, billing mode, module budgets with change log`

### Task 2: BillableLine, Payment, balances

**Files:**
- Create: migration `2026_11_01_000002_create_billing_tables.php` (`billable_lines`, `payments`), `app/Models/BillableLine.php`, `app/Models/Payment.php`, `app/Enums/PaymentType.php`, `app/Services/ClientAccount.php`
- Modify: `app/Models/Client.php` (`billableLines()`, `payments()`), `app/Models/Project.php` (`payments()`)
- Test: `tests/Feature/ClientAccountTest.php`

**Interfaces:**
- Produces: `billable_lines` (`client_id`, `project_id?`, `source_type?`/`source_id?` morph, `recurring_charge_id?`, `period?` string, `description`, `quantity decimal(8,2)=1`, `unit_price_cents`, `amount_cents`, `invoice_id?`, timestamps; unique `(recurring_charge_id, period)`); `BillableLine::scopePending` (`invoice_id` null); `payments` (`client_id`, `project_id?`, `invoice_id?`, `amount_cents`, `paid_on date`, `type`, `method?`, `notes?`); `PaymentType::{Project='project',OtherService='other_service',ProjectUpdate='project_update',Maintenance='maintenance'}`; `ClientAccount::balanceCents(Client): int` = payments − sum of `total_cents` of non-void invoices (invoices table arrives in Task 6 — until then query via `Invoice` guarded by `class_exists`/stub: **implement `balanceCents` in this task over payments only and extend it in Task 6**); `ClientAccount::softBalanceCents(Project): int` = sum of the project's module budgets − payments with that `project_id`, floored at 0.

- [ ] **Step 1: Write failing tests**: pending scope returns only `invoice_id=null`; unique `(recurring_charge_id, period)` rejects duplicates; balance with only a 300 payment → +30000; soft balance project budget 100000, payment 30000 → 70000; soft balance never negative.
- [ ] **Step 2–4:** run FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: billable lines, payments and client balance`

### Task 3: Pending work from completed tasks

**Files:**
- Create: `app/Services/BillableService.php`
- Test: `tests/Feature/BillableServiceTest.php`

**Interfaces:**
- Consumes: `BillableLine`, `Client::billing_mode`, `Module::hourly_rate_cents`.
- Produces: `BillableService::syncCompletedWork(Client $client): void` — idempotent. For every **published** task in the client's projects whose `board_column` is `Done`, ensure exactly one *uninvoiced-or-invoiced* line exists with `source = task`: amount = `task.price` (→ cents) if set; else for an `hourly` client `task->totalEstimateHours() × module.hourly_rate_cents`; else the task is not billable (no line). Description = task title; qty 1. Uninvoiced task lines whose task is no longer Done/published (or no longer has an amount) are **deleted**; already-invoiced lines are never touched. `BillableService::pendingFor(Client): Collection` = `BillableLine::pending()` for the client ordered by id.
- "Milestone achieved" has no separate concept in the tracker; a Done task *is* the milestone (ledger ruling).

- [ ] **Step 1: Write failing tests**: Done task with price 200 → one pending line of 20000; running twice → still one; hourly client + unpriced Done task 2h × 5000 → 10000; fixed client + unpriced task → no line; task moved back to In Progress while uninvoiced → line removed; invoiced line (set `invoice_id`) survives the task moving back; draft task and other client's task → no line.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: pending billable lines from completed work`

### Task 4: Recurring charges

**Files:**
- Create: migration `2026_11_01_000003_create_recurring_charges_table.php`, `app/Models/RecurringCharge.php`, `app/Enums/RecurringFrequency.php`, `app/Console/Commands/GenerateRecurringCharges.php`, `app/Livewire/Billing/Recurring.php`, `resources/views/livewire/billing/recurring.blade.php`
- Modify: `routes/console.php` (`Schedule::command('billing:generate-recurring')->daily()`), `routes/web.php` (admin-gated `billing.recurring`), `resources/views/layouts/app.blade.php` nav (owner only: "Billing")
- Test: `tests/Feature/RecurringChargeTest.php`

**Interfaces:**
- Produces: `recurring_charges` (`client_id`, `project_id?`, `description`, `amount_cents`, `frequency` monthly|yearly, `next_due_on date`, `active bool`); `RecurringCharge::generateDue(CarbonInterface $today): int` — for every due period (`next_due_on <= today`) creates a pending `BillableLine` (client = charge's client, project if set, `period` = `Y-m` for monthly / `Y` for yearly, `recurring_charge_id`), then advances `next_due_on`; **catches up each missed period once**; skips inactive; relies on the `(recurring_charge_id, period)` unique key so reruns never duplicate. Command `billing:generate-recurring` calls it for all charges. Livewire page (route behind `can:manage-money` middleware): list/create/toggle-active charges for a client or project.

- [ ] **Step 1: Write failing tests**: monthly charge due 2026-09-01 run on 2026-09-01 → one line, `next_due_on` 2026-10-01; run again same day → still one (**Review Focus 4**); run on 2026-12-15 with `next_due_on` 2026-09-01 → lines for 09,10,11,12 exactly once each; yearly → `Y` period, next +1 year; inactive → none; project-scoped charge line carries `project_id`; non-owner hitting the page → 403; command is registered in the schedule.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: recurring charges with scheduled generation`

### Task 5: Owner bank accounts

**Files:**
- Create: migration `2026_11_01_000004_create_bank_accounts_table.php`, `app/Models/BankAccount.php`, `app/Livewire/Billing/BankAccounts.php`, `resources/views/livewire/billing/bank-accounts.blade.php`
- Modify: `routes/web.php` (`billing.bank-accounts`, owner only)
- Test: `tests/Feature/BankAccountTest.php`

**Interfaces:**
- Produces: `BankAccount` (`label`, `account_name`, `bank_name`, `account_number`, `ifsc_swift`, `email?`; account_number and ifsc_swift **encrypted**, hidden from `toArray`); simple add/list/delete Livewire page (owner only).

- [ ] **Step 1: Write failing tests**: account number encrypted at rest (raw column ≠ plaintext); `toArray` hides it; owner can add/delete; non-owner → 403.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: owner bank accounts (encrypted)`

### Task 6: Invoice model + InvoiceService (create, number, void)

**Files:**
- Create: migration `2026_11_01_000005_create_invoices_table.php`, `app/Models/Invoice.php`, `app/Services/InvoiceService.php`
- Modify: `app/Services/ClientAccount.php` (balance subtracts non-void invoice totals), `app/Models/BillableLine.php` (`invoice()` relation)
- Test: `tests/Feature/InvoiceServiceTest.php`

**Interfaces:**
- Produces: `invoices` (`number` unique, `client_id`, `status` sent|void, `issued_on date`, `due_on date?`, `currency`, `subtotal_cents`, `tax_percent decimal(5,2)`, `tax_cents`, `total_cents`, `bank_account_id?`, `inr_rate decimal(10,4)?`, `terms text?`, `emailed_at timestamp?`, timestamps); `Invoice::lines(): HasMany<BillableLine>`, `client()`, `payments()`, `isPaid(): bool`, `paidCents(): int`.
- `InvoiceService::create(Client $client, array $lineIds, array $customLines = [], float $taxPercent = 0.0, ?int $bankAccountId = null, ?float $inrRate = null): Invoice` — in one DB transaction: every id in `$lineIds` must be a **pending line of this client** (else `ValidationException`; nothing created); `$customLines` = `[['description'=>…,'quantity'=>…,'unit_price'=>…]]` become new lines (cents via `Money::toCents`); at least one line required; computes subtotal/tax/total; `terms` from `client.payment_terms`; `due_on` from terms when it parses `net-N`, else null; assigns number `INV-YYYYMMDD-NN` safely (NN = count of that day's invoices + 1, retried on unique-key collision); attaches lines; status `sent`. `InvoiceService::void(Invoice $invoice): void` — no-op if already void; sets status `void`, sets its lines' `invoice_id` back to null (custom/one-off lines stay pending too).
- `ClientAccount::balanceCents` now = payments − Σ `total_cents` of `sent` invoices.

- [ ] **Step 1: Write failing tests**: 2 pending lines (100, 200) + tax 10% → subtotal 30000, tax 3000, total 33000, lines attached, number matches `/^INV-\d{8}-01$/`, balance = −33000 (**invoice moves the balance, line completion alone did not**); second invoice same day → `-02` (**Review Focus 1**) and a forced collision (pre-insert the next number) still yields a unique number; unticked pending line stays `invoice_id null`; line of another client → `ValidationException`, no invoice row; zero lines → error; custom line added; void → status `void`, lines pending again, balance back to 0, second `void` is a no-op (**Review Focus 2**); tax rounding half-up (subtotal 1005 cents, 10% → 101).
- [ ] **Step 2–4:** FAIL → implement → PASS (also `ClientAccountTest`).
- [ ] **Step 5: Commit** `feat: invoices — creation, numbering, void, balance`

### Task 7: Owner invoice builder + invoice list/show

**Files:**
- Create: `app/Livewire/Invoices/Create.php`, `app/Livewire/Invoices/Index.php`, `app/Livewire/Invoices/Show.php`, views under `resources/views/livewire/invoices/`, `resources/views/invoices/_body.blade.php` (shared invoice markup: header, bill-to block, line table, subtotal/tax/total, optional INR line, terms, contact, bank block — mirror the owner's existing invoice layout)
- Modify: `routes/web.php` (owner-only `invoices.index`, `invoices.create`, `invoices.show`), nav entry, `show.blade.php` of client list optional link
- Test: `tests/Feature/InvoiceBuilderTest.php`

**Interfaces:**
- Consumes: `BillableService::syncCompletedWork/pendingFor`, `InvoiceService::create/void`, `Money`, `BankAccount`.
- Produces: `Invoices\Create` (query `?client={id}`; `mount` calls `syncCompletedWork`; props: selected line ids (all pre-ticked), custom lines, `taxPercent`, `bankAccountId`, `inrRate`; `save()` authorizes `manage-money`, calls the service, redirects to `invoices.show`); `Invoices\Index` (all invoices, status, derived paid, filter by client); `Invoices\Show` (renders `_body`, Void button → `InvoiceService::void`). All behind the `manage-money` gate (403 otherwise).

- [ ] **Step 1: Write failing tests**: pending lines listed pre-ticked; unticking one then saving leaves it pending and invoices the rest; custom line + bank account + INR rate render in `Show` (`assertSee` `$1,980.00`-style total and the INR line); void button works and the list shows `void`; developer/client → 403 on all three routes; invoice body escapes description (`<script>` not raw).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: owner invoice builder, list and detail`

### Task 8: PDF and email delivery

**Files:**
- Modify: `composer.json`/`composer.lock` (`barryvdh/laravel-dompdf`)
- Create: `app/Mail/InvoiceMail.php`, `resources/views/emails/invoice.blade.php`, `resources/views/invoices/pdf.blade.php`, `app/Http/Controllers/InvoicePdfController.php`
- Modify: `app/Services/InvoiceService.php` (dispatch mail after commit), `routes/web.php` (`invoices.pdf` GET `invoices/{invoice}/pdf`, auth), `app/Livewire/Invoices/Show.php` (flag "no contact email", "Resend" button)
- Test: `tests/Feature/InvoiceDeliveryTest.php`

**Interfaces:**
- Produces: `InvoiceMail` (queued `Mailable`; attaches the PDF; HTML body includes the same `invoices._body` partial so items/totals appear in the body); `InvoiceService::create` queues it to `client.contact_email` after the transaction commits, only if `emailed_at` is null, then sets `emailed_at` (`InvoiceService::send(Invoice): bool` does the guard: returns false when `emailed_at` set or client has no email). `GET invoices/{invoice}/pdf`: owner (`manage-money`) or the client user whose `client_id` matches; **everyone else gets 404**; response `application/pdf`.

- [ ] **Step 1: Write failing tests** (`Mail::fake()`): creating an invoice queues exactly one `InvoiceMail` to the client's contact email with a PDF attachment and the body containing a line description and the total; calling `send` again → still one (**Review Focus 5**); client with null `contact_email` → invoice created, no mail, `Show` renders the warning; PDF route: owner 200 + `application/pdf`, own client 200, other client's user 404, guest redirect to login (**Review Focus 3**).
- [ ] **Step 2: Run** → FAIL. **Step 3:** `composer require barryvdh/laravel-dompdf` (PowerShell if needed) and implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: invoice PDF and email delivery`

### Task 9: Record payments (owner)

**Files:**
- Create: `app/Livewire/Payments/Index.php`, `resources/views/livewire/payments/index.blade.php`
- Modify: `routes/web.php` (owner-only `payments.index`), nav
- Test: `tests/Feature/PaymentTest.php`

**Interfaces:**
- Produces: owner page to record a payment (`client_id`, optional `project_id` of that client, optional `invoice_id` of that client and not void, `amount` → cents, `paid_on`, `type: PaymentType`, `method?`, `notes?`) and list payments with the client's running balance shown; validation: amount > 0, project/invoice must belong to the chosen client (else 404/validation error), no payment against a void invoice.

- [ ] **Step 1: Write failing tests**: payment 300 on a client with invoiced 200 → balance +100 (the owner's worked example: invoiced 200, balance −200; pay 300 → +100); linking an invoice marks `isPaid()` when total reached; another client's project/invoice id rejected; void invoice rejected; zero/negative amount rejected; non-owner 403.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: record client payments`

### Task 10: Client portal — Invoices, Payments, balance

**Files:**
- Create: `app/Livewire/Portal/Invoices.php`, `app/Livewire/Portal/Payments.php`, views under `resources/views/livewire/portal/`
- Modify: `routes/web.php` (inside the `role:client` portal group: `portal.invoices`, `portal.payments`), `resources/views/livewire/portal/dashboard.blade.php` (balance + soft balance card), portal nav in `layouts/app.blade.php`
- Test: `tests/Feature/PortalMoneyTest.php`

**Interfaces:**
- Produces: portal pages listing only `Invoice::where('client_id', auth()->user()->client_id)` (number, date, total, status incl. derived paid, PDF link to `invoices.pdf`) and `Payment` rows for that client (date, amount, type); dashboard shows the client's `balanceCents` and per-project soft balance, labelled "expected". **No cost, hours-cost, profit, other clients, or line `source` internals.**

- [ ] **Step 1: Write failing tests**: client K sees own invoices/payments/balance and not client L's numbers or amounts (`assertDontSee`) (**Review Focus 3**); PDF link for own invoice resolves, other client's → 404; client user with `client_id` null sees empty lists; rendered pages contain no cost-rate/profit text; non-client roles get 403 on portal routes.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: client portal invoices, payments and balance`

### Task 11: Owner analysis — project economics

**Files:**
- Create: `app/Services/ProjectEconomics.php`, `app/Livewire/Projects/Economics.php`, `resources/views/livewire/projects/economics.blade.php`
- Modify: `routes/web.php` (owner-only `projects.economics`), link from the project page (owner only)
- Test: `tests/Feature/ProjectEconomicsTest.php`

**Interfaces:**
- Produces: `ProjectEconomics::for(Project): array` with `hours` (all time entries on the project's tasks and subtasks), `invoiced_cents` (sum of the project's lines on `sent` invoices), `received_cents` (payments with this `project_id`), `cost_cents` (Σ `derivedCost()`; **flat-rate users counted once per project** — closes the Phase 1 deferred item), `profit_cents = received − cost`, `hourly_received_cents` and `hourly_invoiced_cents` (÷ hours, `null` when hours = 0). Page is owner-only.

- [ ] **Step 1: Write failing tests**: hourly user 60/h logs 10h → cost 60000; flat user with 3 entries on the project → cost counted once; invoiced 100000 + received 50000 → profit 50000 − cost; effective hourly = received ÷ hours; hours 0 → hourly null, no divide-by-zero; void invoice excluded from invoiced; non-owner 403; another project's data excluded.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: project economics analysis`

### Task 12: Seed, full suite, docs

**Files:**
- Modify: `database/seeders/DemoSeeder.php` (billing mode, one hourly module budget, one recurring charge, one bank account — idempotent like Phase 2's guarded seeders), `docs/STATUS.md` (Phase 3 built, test count, links to this spec/plan, deferred items, "What's next": Phase 4 Chat; note expenses feature deferred by the owner)

- [ ] **Step 1:** `php artisan test > out.txt 2>&1; echo $?` → exit 0; record the count.
- [ ] **Step 2:** Run `db:seed --class=DemoSeeder` twice on a throwaway sqlite file → no duplicate recurring charge / bank account rows.
- [ ] **Step 3:** Update `docs/STATUS.md`; commit `docs: phase 3 money complete`.

---

## Self-review

- **Spec coverage:** billing mode + module budgets + change log (T1); pending work from Done tasks (T3); recurring client/project monthly/yearly with removal at invoice time via unticking (T4, T7); balance only on invoice/payment (T2, T6, T9); invoice number, tax, bank account, INR line, terms, void (T5–T7); PDF + email with items in body, client Invoices/Payments sections (T8, T10); soft balance (T2, T10); payment types (T9); analysis incl. flat-cost dedup (T11); owner-only money and client isolation (gate in T1, tests in T7–T11). Expenses/gateway etc. intentionally absent.
- **Names:** `BillableLine`, `Invoice`, `Payment`, `RecurringCharge`, `BankAccount`, `Money`, `BillableService`, `InvoiceService`, `ClientAccount`, `ProjectEconomics`, gate `manage-money`, route names — defined once and reused unchanged.
- **Proportion:** tasks list signatures, rules and assertion lists; bodies are left to implementers.
- **Rulings baked in (mine):** a Done task is the "milestone"; task amount = `price`, else hours × module rate for hourly clients, else not billable; invoice status stored sent/void with derived paid; INR rate manual; money in integer cents; invoice dispatch guarded by `emailed_at`.
