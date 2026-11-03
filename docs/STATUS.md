# Project Tracker ("Helm") — Status & Next Steps

_Last updated: 2026-11-01. Branch: `local-currency` (local-currency work on top of phase 3; merge to `main` pending the owner). Tests: **628 passing** (Pest). Cypress specs written but not run on this machine (Electron crashes — run on CI)._

This is the living "where are we / what's next" doc. The task-by-task build plan is
[docs/superpowers/plans/2026-10-26-project-tracker-phase-1.md](superpowers/plans/2026-10-26-project-tracker-phase-1.md);
the design spec is [docs/superpowers/specs/2026-10-26-project-tracker-design.md](superpowers/specs/2026-10-26-project-tracker-design.md).

---

## Where we are

### ✅ Phase 1 backend — complete & reviewed
All 14 planned tasks built test-first, each task-reviewed + fixed, then one whole-branch
review with a fix wave. Working, tested engine:

- Users + roles + cost rates (spatie), super-admin gate bypass.
- Clients (with contact details + **encrypted** bank details).
- Projects + membership + access policy (deny-by-default, cross-client isolation).
- Modules → Tasks (draft/published, scope pricing, labels, assignees, testing add-on,
  board column, section) → Subtasks.
- Checklists with **hours-weighted progress rollup** (ProgressService).
- Time logging with **derived cost** (TimeEntry).
- **Working-day deadline estimator** + dual progress bars (EstimateService).
- Per-user/per-project permission overrides + section access.
- Scoped **client portal** (create/comment/priority/labels/approve).

### 🎨 Frontend pass — complete (2026-10-30 → 2026-10-31)
Direction chosen: **Modern SaaS workspace** — slate + indigo, Inter / Inter Tight,
sidebar app shell. Signature element: the **health meter** (checklist % vs time-used %,
time bar turns amber→rose when over budget).

**Done:**
- Design tokens (fonts, palette, shadows) — `tailwind.config.js`, `resources/css/app.css`.
- Sidebar + topbar **app shell** — `resources/views/layouts/app.blade.php` (role-aware nav,
  user menu, working logout, mobile drawer). Fixes the earlier blank-page bug
  (`components/layouts/app.blade.php`).
- Reusable UI kit — `resources/views/components/ui/` (card, button, badge, stat,
  empty-state, page-header, **health-meter**).
- Reshaped screens: **Dashboard** (stats + recent + quick actions), **Projects list**
  (cards + health meter), **Project page = interactive workspace** (add module / task /
  subtask / checklist + toggle, inline, authorized & project-scoped), **Clients**,
  **Portal dashboard**.
- Supporting: `logout` route, `DemoSeeder` (demo client + projects + tasks), smoke tests
  (`LayoutSmokeTest`, `ProjectWorkspaceTest`).

### ✅ Phase 2 Resources — built (2026-10-31)
Spec: [docs/superpowers/specs/2026-10-31-phase-2-resources-design.md](superpowers/specs/2026-10-31-phase-2-resources-design.md);
plan: [docs/superpowers/plans/2026-10-31-phase-2-resources.md](superpowers/plans/2026-10-31-phase-2-resources.md).
Resource containers → items (text / image / file), per-item assignees (assigned by super
admin only), encrypted values, private files with a permission-checked download route,
comments on items, and a client portal embed with staff names hidden from clients.
`DemoSeeder` also adds one demo Resource (idempotent).

### ✅ Phase 3 Money — built (2026-11-01)
Spec: [docs/superpowers/specs/2026-10-31-phase-3-money-design.md](superpowers/specs/2026-10-31-phase-3-money-design.md);
plan: [docs/superpowers/plans/2026-10-31-phase-3-money.md](superpowers/plans/2026-10-31-phase-3-money.md).
Money is owner-only (`manage-money` gate); amounts are integer cents.

- Client billing mode (fixed/hourly) + module budgets (hours, rate, amount, deadline) with a change log.
- Pending billable lines from Done tasks, plus recurring charges (monthly/yearly, client or project)
  generated daily by `billing:generate-recurring`.
- Invoices numbered `INV-YYYYMMDD-NN`, tax, optional INR line, terms, void, and an **encrypted bank-account snapshot**.
- Invoice PDF (dompdf) + queued email with items in the body; Resend button.
- Payments; per-client balance + a soft balance (unbilled work) shown to the owner.
- Client portal: Invoices and Payments sections, balance, cross-client isolation.
- Owner-only project economics (revenue vs. derived cost; flat-rate cost counted once per project).
- `DemoSeeder` also seeds hourly billing, one module budget, one recurring charge and one demo bank account (idempotent; no invoices/payments).

### ✅ Local currency — built (branch `local-currency`)
Spec: [docs/superpowers/specs/2026-11-02-local-currency-design.md](superpowers/specs/2026-11-02-local-currency-design.md). Owner-only; **the client portal, invoice PDF and invoice email never show local currency**.

- **Setting:** system-wide local currency, default **INR**, on the owner-only **Settings** page. It locks once any money is recorded.
- **Exchange rates:** per client currency, insert-only history with one active rate (older rows are only deactivated). The active rate prefills the payment and invoice forms and stays editable; rates are positive with up to 6 decimals.
- **Payments** store a frozen exchange rate plus the local amount and currency, and show both. Legacy payments get a backfilled local value and can have a rate set later.
- **Invoices** freeze a rate when created; the local total shows on the owner list/detail only, never in print or email.
- **Dashboard, project and client pages** show local figures with all-or-nothing totals: if any payment/invoice lacks a rate the total is hidden and a "N without a rate" note is shown.
- **Payment documents:** up to 5 per payment (PDF/image), stored on the private disk, owner-only download route (`nosniff`), removed with the payment.
- Guard: `tests/Feature/PortalLocalCurrencyPrivacyTest.php` renders every portal page as a client and asserts no local figure, rate, document name or settings text appears.

---

## Frontend pass — completed items

### 1. ✅ Descriptions (2026-10-30)
Added `description` to projects + modules (migration + `$fillable`), surfaced everywhere, covered by `ProjectWorkspaceTest`.

### 2. ✅ Real create/edit forms (2026-10-30)
Project form (name, client, description, dates, buffer, status), Task form (all fields: priority, labels, assignees, scope+price, testing add-on, board column, client-visible). Task edit route added (`tasks/{task}/edit`).

### 3. ✅ Wire unreachable components (2026-10-30)
Kanban board (route + Board tab, styled), Timeline (estimated/logged hours surfaced on project page), Time logger (embedded per-task in workspace).

### 4. ✅ Client portal detail (2026-10-30)
Portal project view restyled: request-task form with validation, task cards with approve button, priority button group, comment toggle form.

### 5. ✅ Design consistency (2026-10-30)
Auth pages (login/register/forgot) restyled to match palette. Profile page uses `x-ui.page-header` + `x-ui.card`. Auth form components (text-input, input-label, primary-button) updated to system CSS classes.

### 6. ✅ Security hardening fix wave (2026-10-31)
- Logger: allowlist `timeableType` + `authorize('comment', $task)` — cross-project tamper blocked
- Show: `Task::visibleTo()` scope in workspace render — draft leak fixed
- Tasks/Form: Scope & pricing card gated behind `updateCostTime` policy — developers can't see cost fields
- 10 new tests (4 security tests added this wave)

## What's next

### Later phases (from the spec)
- **Phase 4 — Chat (next)**: in-app chat per project + chat-to-task.
- Deferred by design: the owner's separate business-expenses feature (AI, marketing, employee costs).
- **First-run setup wizard (future, after the local-currency work and the normal process are complete)** — owner's note 2026-11-02: when the system is installed for another client/business there must be an essential-settings setup, in this order:
  1. **Currencies** — add at least one currency (and its exchange rate to the local currency once a second one exists).
  2. **Local currency** — select the system's local currency (default today: INR).
  3. **Time zone** — select the system time zone (not a setting yet; dates currently use the app default).
  4. **Users** — add the first user(s) (the owner/super admin, then team).
  5. **Business details** (owner confirmed 2026-11-02 that "account" means this) — the business's own details used on invoices: company name, tagline, contact email and phones (today `BILLING_*` env keys), plus the bank account(s) shown on invoices (today the Bank accounts page).
  The wizard should block normal use until these are done, and is the natural onboarding step for each tenant in Phase 5. Not designed or built yet.
- **Phase 5 — Multi-tenancy**: spatie multi-DB (one DB/tenant) + landlord DB + feature flags.

---

## Phase 1 and 2 leftovers

Cleared on branch `cleanup-leftovers`: Time Logger now groups entries per user with subtotals, per-project section scope, `approveTask` now 422s on a non-Done task,
resource edit/delete (files + comments cleaned up), item-type guard, download `nosniff` + `whereNumber`,
shared `rpSetup()` moved to `tests/Pest.php`, comments oldest-first, null `client_id` guard.

Still open by choice:
- **Flat-rate time cost** `derivedCost()` returns the flat amount per entry; `ProjectEconomics` de-dups once per project.
- `client_approval` and task `section` stay raw strings (two values each; an enum adds nothing yet).
- Cypress cannot run on this machine (Electron crash) — run specs on CI.

## Phase 3 deferred items / known limits

- **Payments can be deleted (owner, audit-logged) but not edited**; Livewire disables the submit button during a request.
- Pending custom/recurring lines can be discarded in the builder; task-sourced lines re-sync from tasks and cannot.
- Invoices keep a `lines_snapshot` (JSON) so voided/edited work never changes what an invoice shows; legacy invoices fall back to live lines.
- Budgets are bounded (hours 99999.99, rate/amount 9,999,999.99); `budgetCents()` returns null on absurd stored values.
- Overpayment is allowed silently (acts as credit).
- Single-currency assumption for cost rates (exchange rates are covered under Local currency).
- Invoice-number retry is only race-safe on SQLite/Postgres (MySQL REPEATABLE READ would need
  `lockForUpdate` on the max query); `BillableService` `lockForUpdate` is a no-op on SQLite.
- Economics aggregates run in memory (move to SQL at ~100k entries).
- Invoice PDF layout only verified via tests — eyeball a real PDF.
- Shared `_body` partial lost mobile stacking on the invoice Show page.
- `emailed_at` means "queued", not "delivered" (Resend is the recovery path).
- `amount_cents` on billable_lines/payments is 32-bit in Postgres/MySQL; per-line cap of
  2,000,000,000 cents is enforced in the service.


## Local currency deferred items / known limits

- No live rate lookup: rates are typed manually.
- No FX gain/loss tracking between invoice and payment rates.
- Partial totals are hidden rather than estimated.
- Old invoices without a rate show no local total (not backfilled).
- The `restrictOnDelete` client migration is still deferred.
- Deleting a client cascades its payments' document rows but orphans the files on disk.
- `database/seeders/DemoSeeder.php` (the owner's own uncommitted file) still writes the renamed `inr_rate` column; change it to `exchange_rate`.

---

## How to resume

```bash
# deps already installed. If fresh:
composer install && npm install

php artisan migrate
php artisan db:seed            # super admin (admin@example.com / password) + roles
php artisan db:seed --class=DemoSeeder   # demo client + projects + tasks (idempotent)

npm run build                  # or: npm run dev
php artisan serve              # http://127.0.0.1:8000
```

**Phase 3 setup:**
- `.env` keys for invoices: `BILLING_COMPANY_NAME`, `BILLING_TAGLINE`, `BILLING_CONTACT_EMAIL`, `BILLING_CONTACT_PHONES`.
- Invoice emails are queued: run `php artisan queue:work`.
- Recurring charges need the scheduler: `php artisan schedule:work` in dev, cron `schedule:run` every minute in prod.
- Real email delivery needs `MAIL_*` config (currently `MAIL_MAILER=log`).

**Local currency setup:** run `php artisan migrate` (adds rates, local columns, payment documents), open **Settings** as the owner, confirm the local currency (default INR) and enter a rate for each client currency. Set it before recording money, because it locks afterwards. Documents live on the private `local` disk (`storage/app/private`), not `public`.

**Logins:** super admin `admin@example.com` / `password`; client (portal) `client@example.com` / `password`.

**Tooling notes for this machine:** run `php artisan test` via bash with a file redirect
(`php artisan test > out.txt 2>&1; echo $?`) — piping through PowerShell `Select-Object`
misreports the exit code. Don't run `npx`/node in Git Bash (crashes); use PowerShell for npm.

---

## UI redesign (done)

Blue/slate look with light + dark mode across every screen.

- **Dark mode:** Tailwind `darkMode: 'class'`; an Alpine `theme` store toggles `.dark` on `<html>` (persisted, `_theme-script` partial prevents flash). Toggle is in the user menu.
- **Where the look lives:** `tailwind.config.js` (palette: `brand` = blue, `slate`/gray), `resources/css/app.css` (`.card .btn* .input .label .badge-* .link` etc.), `resources/views/components/ui` (badge, page-header, stat, table-card, filter-card, toast, health-meter).
- **Known ceiling:** dark mode for raw utilities (`bg-white`, `text-slate-*`, `border-slate-*`...) is the "DARK OVERRIDES" block at the bottom of `app.css`, not per-view `dark:` classes. An element carrying `bg-white` cannot use `dark:bg-*` (the override wins); use an arbitrary colour or a component class instead. New code should prefer component classes.
- `docs/desgin-example` is the old Vue reference only and is gitignored by the owner.
- Sweep note: Playwright/Python are not available on this machine, so the dark sweep was a class audit (grep of used utilities vs overrides), not screenshots.

## Client pages (ui-redesign)
- Clients now follow the owner's reference: list (Client / Contact / Financial Summary / Status / Actions, status + per-page filters, search by name, code, contact, email), a new Show page (summary cards, Details / Projects / Invoices tabs) and card-style create/edit forms. Owner (super admin) only.
- New `clients` columns: `type` (company/individual), `client_code` (auto `CLI-0001`, unique, back-filled), `status` (active/inactive/archived), `website`, `city`, `state`, `postal_code`, `country`, `notes` (internal, never shown in the portal). Bank details are never shown on the Show page.
- Delete is a hard delete, allowed only when the client has no projects, invoices, payments, billable lines, recurring charges or users; otherwise an error toast says what blocks it. To retire a client, set its status to archived in the edit form.
- `projects/create?client=ID` preselects the client (used by the Add Project links).

## Dashboard (reference design)
- The owner dashboard follows the old panel: header (period + Refresh) -> Total Revenue / Outstanding Balance / Active Clients / This Month -> Revenue Trend | Payment Status -> Quick Actions -> Recent Invoices | Top Clients -> Upcoming Due Dates -> a "Work" section (projects, open tasks, hours, recent projects). Owner-only, all money in the local currency.
- Charts are server-rendered SVG, no JS: `ui/line-chart` (smooth line, dot per point, grid both ways, "nice" integer-cent y scale; empty = 0.00-1.00) and `ui/doughnut` (stroke-dasharray segments, legend always shown).
- Payment Status classifies invoices issued in the period: void; sent and paid (payments >= positive total) = Paid; sent, unpaid and `due_on` before today = Overdue; otherwise Pending (due today / no due date). Logic: `DashboardMoney::invoiceState` / `invoiceStatusCounts`.
- Top Clients rank by local revenue in the period (clients with 0 still listed); Outstanding per client shows "—" when no rate exists.
