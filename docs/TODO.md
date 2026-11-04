# TODO: what is left

_Created 2026-11-04. This list covers only what is still open. Tick items off and move finished modules up to "Polished" as they are done. Older status detail lives in [STATUS.md](STATUS.md)._

"Polish" means the same treatment the Projects module got: the new look (breadcrumbs, filters, icons, compact layouts, light and dark), clear statuses, in-place editing, sensible empty states, tests, and a real-browser check.

---

## 1. Polish modules (current focus)

### Already polished
- [x] Projects (with modules, tasks, subtasks, board, sprints, resources, budgets)
- [x] Currency
- [x] Labels
- [x] Task types

### To polish, in this order

#### 1.1 Invoices
Today: create, edit, show and list pages; draft / sent / void; PDF; email and resend; sprint lines; frozen exchange rate; bank-account snapshot.
- [ ] Restyle list, create, edit and show pages in the new look (breadcrumbs, filter bar, status chips, icons, compact layout).
- [ ] Make the statuses explicit and consistent: draft, sent, partly paid, paid, overdue, void.
- [ ] Polish the "build an invoice from a completed sprint or done tasks" flow (pick sprint lines, see the task codes, totals).
- [ ] Client portal invoice pages: same polish, still never showing local currency.
- [ ] Check a real PDF and the email by eye.
- [ ] Fix invoice-number generation so it is safe under MySQL (it is only race-safe on SQLite or Postgres today).
- [ ] Tests and a real-browser check, light and dark.

#### 1.2 Payments
Today: record payments with a frozen exchange rate and local amount; up to 5 documents; edit, show and delete; per-client balance.
- [ ] Restyle list, record, edit and show pages in the new look.
- [ ] Show clearly which invoice(s) a payment covers and update the invoice status from payments.
- [ ] Decide how overpayment is handled (today it is accepted silently and acts as credit).
- [ ] Clean up payment document files when a payment or client is deleted (files are orphaned today).
- [ ] Tests and a real-browser check, light and dark.

#### 1.3 Recurring services (as subscriptions)
Today: monthly or yearly charges for a client or a project, generated daily by `billing:generate-recurring`; the page can create one and pause or resume it.
- [ ] Rename and reshape the module as **Subscriptions** (client or project), with a list showing status (active, paused, ended), next due date and amount.
- [ ] Add edit, delete and end/cancel (the UI has only create and pause today).
- [ ] Keep a price-change history and an end date.
- [ ] Show a subscription's generated charges and the invoices they ended up on.
- [ ] Show upcoming renewals on the dashboard.
- [ ] Document the scheduler setup (`schedule:work` in dev, cron in production).
- [ ] Tests and a real-browser check, light and dark.

#### 1.4 Users and team roles
Today: roles `super_admin`, `project_manager`, `developer`, `designer`, `tester`, `client`; project membership with a role on the project and an access scope; cost rates per user. There is no screen to manage users, so users only exist through seeders and client accounts.
- [ ] Build a **Users** module: list, add, edit, deactivate, reset password, invite by email.
- [ ] Extend the team roles: Developer, Designer, Tester, QA, BA (business analyst) and others as needed. Decide whether roles are a fixed list or managed by the super admin like task types.
- [ ] Assign people to projects from the user page, with the role on that project and the access scope.
- [ ] Per-role permissions (what each role can see and do, including money visibility) and a team view.
- [ ] Link client users to their client.
- [ ] Tests and a real-browser check, light and dark.

---

## 2. Leftovers to close
- [ ] Final security review of the whole app (encrypted Resources, who sees money, client visibility, uploads, permissions).
- [ ] Repeatable browser tests in the repo (Cypress or Playwright; the old Cypress specs are out of date and cannot run on this machine).
- [ ] Decide on client file uploads on their own comments (types, size, who sees them).
- [ ] Decide on sprint role limits (any project member can manage sprints today).
- [ ] Safer client delete rule (`restrictOnDelete`).
- [ ] Look at a real invoice PDF (layout is only checked through tests).

## 3. Later (planned features)
- [ ] First-run setup wizard: currencies, local currency, time zone, first users, business details for invoices.
- [ ] Phase 4, Chat: in-app chat per project and chat-to-task.
- [ ] Business expenses (AI, marketing, employee costs), deferred by design.
- [ ] Phase 5, Multi-tenancy: a database per tenant, a master database with feature flags, tenant provisioning.
