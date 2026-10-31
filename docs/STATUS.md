# Project Tracker ("Helm") — Status & Next Steps

_Last updated: 2026-10-31. Branch: `phase-2` (not yet merged to `main`). Tests: **270 passing** (Pest). Cypress specs written but not run on this machine (Electron crashes — run on CI)._

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
- **Phase 3 — Money (next)**: payments, invoices (replace manual PDFs), expenses, profit/loss,
  client-visible payment standing.
- **Phase 4 — Chat**: in-app chat per project + chat-to-task.
- **Phase 5 — Multi-tenancy**: spatie multi-DB (one DB/tenant) + landlord DB + feature flags.

---

## Phase 1 deferred items / known tech-debt (carried from the build ledger)

- **Access-scope section filter unions a user's scopes globally** (not per-project) —
  a user who is frontend on project A + backend on B sees both sections. Accepted ceiling;
  pinned by a test. Fix with a per-project correlated join when it matters.
- **Flat-rate time cost** `derivedCost()` returns the flat amount **per entry**; the
  "once per project" de-dup is Phase 3's aggregation job (documented; contract test exists).
- **Time Logger** shows all users' entries for a task (no per-user scope) — confirm intended.
- `client_approval` and task `section` are raw nullable strings (no enum cast) — fine, tidy later.
- Cypress deferred on this machine (Electron illegal-instruction crash) — run specs on CI.
- `approveTask()` silently no-ops on a non-Done task (button hidden in UI).

## Phase 2 deferred items

- No delete/edit flow for resources/items (so no file cleanup on delete).
- Item `type` is a free string at model level (validated only in the panel).
- Download route hardening: `nosniff` header, `whereNumber('item')`.
- Test helper `rpSetup()` is shared across test files via `require_once`.
- Comments are unordered.
- Null `client_id` guard in the `ResourceItem` scope.
- Clients see all assignee candidates only in the admin UI (n/a for clients).

---

## How to resume

```bash
# deps already installed. If fresh:
composer install && npm install

php artisan migrate            # includes the new description migration
php artisan db:seed            # super admin (admin@example.com / password) + roles
php artisan db:seed --class=DemoSeeder   # demo client + projects + tasks (idempotent)

npm run build                  # or: npm run dev
php artisan serve              # http://127.0.0.1:8000
```

**Logins:** super admin `admin@example.com` / `password`; client (portal) `client@example.com` / `password`.

**Tooling notes for this machine:** run `php artisan test` via bash with a file redirect
(`php artisan test > out.txt 2>&1; echo $?`) — piping through PowerShell `Select-Object`
misreports the exit code. Don't run `npx`/node in Git Bash (crashes); use PowerShell for npm.

**Uncommitted work:** the entire frontend pass above is uncommitted on `phase-1`. Commit it
before closing out so it isn't lost.
