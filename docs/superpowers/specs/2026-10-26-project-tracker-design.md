# Project Tracker — Design

*Working title: "Project Tracker" (candidate name: Helm). Date: 2026-10-26.*

## 1. Intent and the why

The owner is a freelancer running what is effectively a small agency: multiple
clients, and per-project a shifting cast of developers, designers, and testers.
The work itself is not the problem — **the gaps around the work are**.
Requirements, files, chat, progress, and payments currently live scattered
across GitHub Projects, WhatsApp, email, Skype, manually-made invoice PDFs, and
the owner's memory. **Nothing leaves a durable record.** When a client asks
"where are we, what have I paid, what's left," the answer has to be
reconstructed from five places every time — and that reconstruction lag reads to
the client as *delay*, even when the work is on track. Delays-as-perceived are
why clients are lost.

The end goal: **one place per project** where everything related to it lives, so
that:

- **Clients** can self-serve progress, see their documents and payment standing,
  and stop needing to ping the owner for status.
- **Team members** have every resource for every project in one place — no
  dependency on a particular laptop or chat thread.
- **The owner** sees the whole business at once: progress across all projects,
  earnings, expenses, and profit/loss — the real standing of the business.

## 2. Who it serves (roles)

One **super admin** (the owner) who can do everything any other role can and can
step into any seat. Other roles are pulled in **per project**, not always all of
them:

- **Client** — logs into their own projects only; follows progress; sees their
  payment standing. In Phase 1 a client can actively **create tasks**, **approve
  a task after it's completed** (a client-approval step on done tasks), **change
  task priority**, **add comments**, and **change labels**. They cannot see money
  internals or other clients' data.
- **Project manager** — runs a project: assigns people, manages the board, owns
  the timeline.
- **Developer / designer / tester** — attached to specific projects. Not every
  project has every type.

**Access rules:**

- Role-based by default, with **per-user, per-project permission overrides** the
  super admin can grant or revoke (e.g. a client who may or may not see team
  details; a developer who sees only the backend side or only the frontend
  side).
- A client is an organization that may have **more than one login user**.
- When a project completes, a member **stays named on it for the record**, but
  their access is removed or narrowed.
- Money (expenses, cost rates, profit/loss) is **owner-only**, never client-
  visible.

**Note on effort:** the fine-grained per-user/per-project/per-section permission
control is the single most complex part of the system. It is built in two steps:
role **defaults first**, then the granular **overrides** layered on. Both land in
Phase 1, but in that order, so the basics are usable before the fine control
exists.

## 3. The core model

Everything hangs off a small, deliberately unified model. The key simplification
the owner arrived at: **an "idea" is just a task, a "todo list" is just a
checklist** — there is essentially **one task entity** with flavors, not three
separate systems.

```
Tenant (spatie/laravel-multitenancy — multi-DATABASE, one DB per tenant; built in the FINAL phase)
Landlord DB (master) — tenants + per-tenant feature flags (enable/disable features per tenant)
└── Users (role: super_admin | project_manager | developer | designer | tester | client)
    ├── cost rate: flat (fixed per project) OR hourly
    └── per-project permission overrides
Client (organization; may have multiple client-users; has a currency)
└── Project (buffer %, start date, currency; timeline & deadline are derived)
    ├── ProjectMember (user ↔ project: role-on-project, access scope, active/removed)
    ├── Resource (type: document | screenshot | credential; shared-with users; credentials encrypted)
    └── Module (ordered)
        └── Task
            ├── assignees (many — e.g. developer + tester)
            ├── estimate hours
            ├── stage/type (incl. "idea" = captured, not yet scheduled)
            ├── priority
            ├── labels (many)
            ├── status: draft (creator only) | published (all project members)
            ├── client-visible flag (super admin controls; not all tasks)
            ├── board status (Backlog → In Progress → Testing → Done)
            ├── edit rights: only assignees can change/comment; devs can't edit cost/time (comment only)
            ├── client approval (pending | approved — set by client on completed tasks)
            ├── testing: enabled (adds % or fixed time to this task) OR a standalone testing task
            ├── scope: in-scope | out-of-scope (+ its own price)
            ├── Subtask (estimate hours, assignee, ordered)
            │   └── ChecklistItem (done/not done, reorderable)   [Subtask: one or more assignees]
            ├── ChecklistItem (done/not done, reorderable)
            ├── Comment (author incl. client)
            └── TimeEntry (hours, started_at, completed_at, date; cost derived — hourly: hours × rate; flat: fixed)
```

### Tasks, ideas, checklists

- A **task** has a description, assignee(s), board status, estimate in hours,
  priority, labels, and a **scope flag** (out-of-scope tasks carry their own
  price so scope creep becomes a billable line instead of silent free work).
  Visibility and edit rights:
  - **Status: draft or published.** A **draft** is visible only to its
    **creator**. A **published** task is visible to **all project members**.
  - **Multiple assignees:** a task can have **several people assigned** (e.g. a
    developer and a tester).
  - **Edit/comment rights:** on a published task, only an **assigned user** can
    comment or change it; everyone else is view-only. **Developers cannot change
    cost or time** (estimates and rates stay with super admin / PM) — they can
    only comment.
  - **Client visibility** is a separate flag the **super admin** controls — **not
    all tasks are client-visible** — plus the client-approval step on completed
    tasks.
- An **idea** is just a task in an early stage — captured, not yet estimated or
  scheduled — and can be **promoted** into a real task later. Viewing "all
  ideas / all todos / all tasks" is **filters over the one task list**, not
  separate screens.
- A task can have **subtasks** (the estimated pieces that feed the deadline
  math).
- Both a task and a subtask can carry a **checklist**: dead-simple items, each
  just done / not done, **reorderable** (drag up/down). Checklists carry **no
  time** and never touch the timeline math.

### Two independent rollups (must not be conflated)

1. **Time → deadline.** Task hours (including any testing add-on) sum to the
   **module** timeline; modules sum to the **project** timeline. Hours convert to
   a **calendar deadline** at a fixed, realistic capacity of **8 hours/day, 5
   days/week**, counting forward from the project start date and **skipping
   weekends**, then a **flat buffer percentage** is applied on top.
2. **Checklist → progress %.** Checklist items done ÷ total give a subtask's
   progress; subtasks' checklists (plus the task's own checklist items) combine
   into the **task's** progress bar; tasks combine into the **module's** progress,
   and up to the project.

A task therefore shows **two bars**: the **checklist bar** (work marked done) and
a **time bar** (hours logged ÷ estimated hours). When they disagree — boxes
nearly all ticked but hours well over estimate — that is the early warning a task
is going sideways. Both bars are derived; neither is hand-set.

**Decided (owner's call deferred to me):** module/project progress is **weighted
by estimate hours**, not a plain average — a 40-hour task moves the bar more than
a 2-hour task. The hours are already present, so the weighting is nearly free and
more honest. Adjustable if it ever needs to be simpler.

### Time logging

Time is logged **at the task and subtask level only** (hours + start/complete
timestamps + date). **Checklists are never timed.** Cost is **not typed in** — it
is **derived** from the person's cost rate, which is either **hourly** (cost =
logged hours × rate) or **flat** (a fixed amount for their work on the project).
From these time entries fall out, for free:

- "How much did I do today / this week / this month" (entries grouped by date).
- Estimate-vs-actual (estimated hours vs logged hours).
- The profit/loss math in Phase 2 (logged hours × cost rate vs. payments
  received, minus expenses).

### Resources: documents, screenshots, credentials (item-level sharing)

A **resource** is anything attached to a project that you keep and share — one
entity with a **type** field (**document/file**, **screenshot/image**,
**credential**, extensible later). All resources use **one shared mechanism**: a
resource is **shared with an explicit list of users**, and **only those users
(plus the super admin) can access it** — a finer, item-level grant alongside task
and role rules.

- **Documents / screenshots** — uploaded files/images attached to a project,
  each shared with chosen people. Only shared people see or download them.
- **Credentials** — stored secrets (label, username, secret/password, URL,
  notes), each shared with chosen people. Only shared people can view them.
  **Secrets are encrypted at rest** (Laravel `encrypted` cast / `Crypt`), never
  stored or logged in plaintext; decryption happens only for shared users on
  view.

The sharing is a single polymorphic relation (resource → users), written once and
reused across every resource type. (Tasks use the draft/published model instead,
not this share list.)

### Chat-to-task (Phase 4, but shapes the model now)

A chat message can be **converted into a task** that lands on the backlog, with a
**link back to the originating message** so there is a record of *why* the task
exists. If that task is out-of-scope, it carries its own price. This is why the
task model has a scope flag and price from Phase 1 even though invoicing arrives
later.

## 4. Scope and phasing

Building everything at once is the surest way for this to never ship; owner
momentum lost is the same failure as a client lost. So it is phased, and each
phase is built on the same model so **nothing is thrown away**.

### Phase 1 — The project core (what gets planned and built first)

- Built **single-tenant but tenancy-ready**: all data access routed through
  Eloquent (DRY) so the final multi-database conversion is central, not scattered.
  No tenant/landlord/feature-flag code yet.
- Users, and **role defaults**, then **per-user/per-project permission
  overrides**. Each user carries a **cost rate (flat or hourly)**.
- **Clients** (orgs with possibly multiple client-users, each with a currency)
  and **projects** under them.
- **Modules → tasks → subtasks**, with **checklists** (reorderable, done/not
  done) driving progress bars that roll up module→project.
- **Task priority, labels, and comments**; client capabilities: create tasks,
  add comments, change priority/labels, and **approve a completed task**.
- A **board per project** — Backlog → In Progress → Testing → Done — drag tasks
  between columns.
- **Visibility flag** per task (client-visible vs internal).
- **Scope flag + price** per task (out-of-scope capture).
- **Time logging** at task/subtask level; cost derived from rate.
- **Deadline estimator**: hours → working-day calendar (8h/5-day, skip weekends)
  + flat buffer %, with the per-task **testing** option (add-on or standalone
  task). This is cheap arithmetic over data Phase 1 already holds, so it lands
  here rather than later.
- **Two progress bars** (checklist + time) and **estimate-vs-actual**.
- **Client login**, read-mostly, scoped to their own projects.
- **Pest** (backend) and **Cypress** (frontend) set up from the start.

### Phase 2 — Resources (documents, screenshots, credentials)

- **Resources** per project — one entity, typed as document/file,
  screenshot/image, or credential; extensible later.
- **Item-level sharing** — each resource shared with an explicit list of people;
  only they (plus super admin) access it. One polymorphic share relation, reused.
- **Credentials encrypted at rest** (Laravel `encrypted` / `Crypt`), never
  plaintext, decrypted only for shared users on view.
- Client-uploadable where the client is shared in.

### Phase 3 — Money and records

- **Payments** assigned to a project, in types: **project payment**, **other
  service payment**, **project update payment**, **project maintenance payment**;
  each with status **received / pending / upcoming** and a date.
- **Invoices** (replacing the manual PDFs), drawing on task/scope prices.
- **Currency per client/project** — amounts stored in the project's currency;
  business-wide profit/loss handles the mix (conversion approach is a Phase 2
  decision, flagged there).
- **Client-visible payment standing** (received / pending / upcoming) per
  project and across the client.
- **Expenses** — developer cost, other resource cost.
- **Profit/loss** per project and across the business = payments received −
  (logged hours × cost rates + expenses); total time taken; estimate-vs-actual
  finish.

### Phase 4 — Everything-in-one-place communication

- **In-app chat** per project, replacing WhatsApp/email/Skype.
- **Chat-to-task** conversion with the back-link described above.

*(Documents / screenshots / credentials moved up to Phase 2 — Resources.)*

### Phase 5 (final) — Multi-tenancy

Built **last**, once Phases 1–3 are done and working single-tenant. Architecture
is fixed by the owner:

- **Multi-database** via `spatie/laravel-multitenancy` — **one database per
  tenant** (not a shared, tenant-scoped DB). Spatie switches the DB connection
  per request; the app's per-tenant data models move largely unchanged into the
  tenant database.
- A **landlord (master) database** holds the list of tenants and **per-tenant
  feature flags** — flags set at the landlord level **enable or disable features
  per tenant**. Candidate tool: **Laravel Pennant**, scoped to the tenant and
  resolved from the landlord (confirm at plan time); the point is not to hand-roll
  a flag engine.
- Signup/onboarding provisions a new tenant database and seeds it.

**Why this stays a conversion, not a rewrite:** Phases 1–3 route all data access
through Eloquent (DRY) and avoid hardcoded connection assumptions, so adding the
landlord + per-tenant connection switch is a central change plus the tenant
provisioning/flag layer — not a rework of every query.

## 5. Constraints

**Stack** (owner's expertise; maintainability by the owner is the top priority):

- **Laravel + Blade + Alpine.js + Livewire** (Livewire for the board, drag, live
  updates; Alpine for small in-page interactions; SortableJS for drag).
- **Tailwind** for styling.
- **Postgres** (my lean; MySQL acceptable — use whatever the host gives cheapest).
- **`spatie/laravel-permission`** for role defaults *and* per-user overrides —
  no hand-rolled permission engine.
- **`spatie/laravel-multitenancy`** (multi-database, one DB per tenant) + a
  **landlord DB** with per-tenant feature flags (**Laravel Pennant** candidate) —
  **built in the final phase**, not now. Phases 1–3 stay single-tenant but
  tenancy-ready.
- File storage on local disk or S3 via Laravel's storage abstraction (one-line
  swap).

**Non-functional:**

- **Fast** — no N+1 swamps; eager-loading and indexes on the board and money
  queries.
- **DRY** — the rollup math, permission checks, and visibility filter are each
  written **once** and reused.
- **Pest + Cypress** from the start, in development, so tracking is real, not
  bolted on.

## 6. Goals / non-goals / anti-goals

**Goals:** one place per project for clients, team, and owner; durable records
that end the five-places reconstruction; progress a client can self-serve;
deadlines derived from real work at real capacity; scope creep captured as
billable; the owner able to see business standing (earnings, expenses,
profit/loss) at a glance.

**Non-goals (for now):** mobile apps (web, browser-based); in-app chat before
Phase 4; a smart/learned buffer (flat % is enough); cross-currency conversion UI
before Phase 3.

**Anti-goals (these mean failure even if it "works"):**

- A permission system so elaborate it never ships, or so coarse a client sees
  another client's data.
- Checklists that demand so much data entry (times, costs per checkbox) that
  they stop being used within a week.
- Two progress numbers that double-count or disagree for hidden reasons.
- A build so large Phase 1 never ships and the owner loses momentum.
- **Credentials stored or logged in plaintext**, or visible to anyone not
  explicitly shared — a security failure even if the feature "works".

## 7. What's left to the builder

Deliberately left to the implementer (sensible defaults, none change *what* gets
built):

- `ChecklistItem` as a polymorphic relation vs. two nullable FKs (task_id /
  subtask_id) — builder's call.
- Board columns are **fixed** in Phase 1 (Backlog / In Progress / Testing /
  Done); per-project configurable columns deferred unless asked.
- Exact schema of permission overrides on top of `spatie` — builder's call,
  within "role defaults first, overrides second."
- **Holidays:** Phase 1 skips only **weekends**, not public holidays.
  *(ponytail: weekend-only working-day calc; add a holidays table if deadlines
  drift noticeably against real calendars.)*
- The project **start date** drives the forward deadline calc; how re-estimation
  on date slip is surfaced is a builder's call within Phase 1.
- Testing add-on default (the "20%" figure mentioned) is a **configurable
  default**, not hard-coded.
```
