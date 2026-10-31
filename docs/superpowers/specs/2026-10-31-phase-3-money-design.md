# Phase 3 — Money: design

_Refines "Phase 3 — Money and records" of the 2026-10-26 design spec and replaces the owner's standalone invoice-generator panel. Decided with the owner on 2026-10-31. Items marked **(my call)** were not decided by the owner — confirm or change._

## Intent
The owner invoices clients monthly from a separate panel today (catalog of systems/services, pick month, pick client, pick bank account, export PDF). Phase 3 moves this into the tracker so billing comes from the real work: completed tasks/milestones, recurring maintenance fees and one-off lines, with a per-client running balance, payments, and an hourly-earnings view per project.

## Concepts

### Billing mode and module budget
- Each **client** is `fixed` or `hourly` (**my call:** a field on Client; mode applies to all its projects).
- Each **module** carries a budget:
  - hourly client → `budget_hours` × `hourly_rate` (rate in the client's currency);
  - fixed client → `budget_amount` + `deadline`.
- Same screens for both. On hourly modules the owner can **raise `budget_hours`** as a feature grows; each change is **logged** (who, when, old → new, reason). Fixed amounts stay fixed (editable, but changes also logged — **my call**).
- A task's billable amount: its own `price` (fixed) or its hours × the module rate (hourly) — **my call**; the module budget is the cap shown to the owner ("$1,000 budget / $200 completed").
- The **billing rate (what the client pays) is separate from the cost rate (what a developer costs)** already in Phase 1.

### Pending billables (nothing hits the balance yet)
- **Completed work** — a task that is Done, or a milestone marked achieved — becomes a *pending billable line* for its client/project. Not charged yet.
- **Recurring charges** — a `RecurringCharge` attached to a **client or a project**: description, amount, frequency (`monthly` | `yearly`), next due date, active flag. When due, it generates a pending billable line automatically (scheduled command, idempotent per charge+period). Archived/inactive charges generate nothing.
- **Custom lines** — the owner can add a one-off line while creating an invoice.

### Invoice
- Created by the owner for one client: choose pending lines to include (everything pre-ticked, any line can be removed for rework or any reason; removed lines **stay pending**), add custom lines, set tax % (default 0, **my call**: one rate per invoice), choose which **owner bank account** to print (or none), optional USD→INR display.
- **Creating the invoice is the only moment the client balance changes**: each included line is posted as a charge. Lines are copied onto the invoice (description, qty, unit price, amount) so later edits to tasks don't change a sent invoice.
- Number: `INV-YYYYMMDD-NN` — date plus a per-day counter, one sequence across all clients (**my call**; no client name in it, per the owner). Never reused.
- Statuses (**my call**): `draft` (not yet created — lines still pending), `sent`, `paid`, `void`. Voiding an invoice reverses its charge and returns its lines to pending.
- Terms text defaults from the client's `payment_terms`.
- **Delivery:** on creation the client is emailed (to the client's contact email) a **PDF attachment plus the same items and details in the email body**. The invoice also appears immediately in the **client's Invoices section** in the portal.

### Payments and balance
- A **payment** is recorded by the owner against a client (optionally an invoice/project): amount, date, type (`project`, `other service`, `project update`, `maintenance` — from the original spec), method/notes. It appears in the client's **Payments section**.
- **Client balance = payments received − invoiced charges.** Negative = client owes (e.g. invoiced 200, paid 0 → −200); paying 300 → +100 credit; later invoices draw the credit down.
- **Soft balance (forecast, not real money):** per project, **budget − payments received** — "what is still expected if the remaining work is delivered by the deadline". Shown separately and labelled as expected (**my call** on the exact formula, taken from the owner's $1,000 / pay 300 → 700 example).
- Clients see their own invoices, payments, balance and soft balance. Costs, cost rates, hours-cost and profit are **owner-only**.

### Analysis (owner-only)
- Per project: hours logged (existing time entries), amount invoiced / received, **effective hourly earnings** (received or invoiced ÷ hours — show both, **my call**), cost of logged hours (hours × cost rate from Phase 1), and profit = received − logged-hours cost.
- Works the same for fixed and hourly clients.

## Goals
- Balance moves only on invoice creation; completed work and recurring charges are *suggestions* the owner curates.
- Invoices are immutable records once created (void to undo).
- Correct money math: store integer minor units or `decimal`, never float; per-client currency; rounding defined once.
- Reuse existing Client/Project/Module/Task/TimeEntry models, UI kit, and the Phase 2 pattern of re-scoping every action through a single visibility rule; clients can only ever reach their own client's invoices/payments.

## Non-goals (this phase)
- **Business expenses** (AI, marketing, employee, hosting costs) and business-wide profit/loss — a separate feature the owner will specify later. Do not build tables for it.
- Online payment gateways, partial-payment allocation across invoices beyond the simple ledger, credit notes, multi-tax, multi-currency conversion of balances (INR is a display line only), reminders/dunning, multi-tenancy (Phase 5).

## Anti-goals (failure even if it works)
- Any client seeing another client's invoices/payments, or any internal cost/profit figure.
- A balance that changes without an invoice or payment event, or an invoice whose lines or total change after it's created.
- Duplicate recurring lines for the same period, or an invoice number reused.
- Emailing the wrong client or sending an invoice twice on a retry.

## Constraints
- Stack as existing (Laravel 12, Livewire 3, Pest, SQLite dev). Mail via Laravel Mail, queued (`QUEUE_CONNECTION=database`); tests use `Mail::fake()`.
- PDF generation needs a library — **builder's call** (e.g. dompdf), adding the one dependency is approved; keep the HTML template shared between the PDF and the email body so they cannot drift.
- Owner bank accounts are a new small entity (name, bank, account no., IFSC/SWIFT, **encrypted** like client bank details) selectable per invoice.

## Left to the builder
Table/column names, money storage type, the exact rule linking a Done task to a billable amount for hourly clients, scheduler wiring for recurring charges, UI layout (Invoices tab/section, invoice builder, ledger view, owner analysis page), PDF template styling (match the owner's current invoice look: header, bill-to block, line table, totals, terms, contact, bank block), test fixtures. Pest tests for every anti-goal above.
