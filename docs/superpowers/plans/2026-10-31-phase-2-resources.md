# Phase 2 — Resources Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-project Resources (containers) holding typed items (text / image / file) that the super admin assigns to specific users, with comments per item.

**Architecture:** Two new models (`Resource`, `ResourceItem`) + an assignee pivot. **One visibility rule** — `ResourceItem::scopeVisibleTo($user)` and `Resource::scopeVisibleTo($user)` — is used by every read path (panel, download route, comments, add-item); an item you can't see is a 404/`findOrFail`, never a 403. One Livewire panel (`Resources\Panel`) embedded in the staff project page and the portal project page. Comments reuse the existing polymorphic `Comment`.

**Tech Stack:** Laravel 12, Livewire 3 (+ `WithFileUploads`), Pest, SQLite, `local` disk (`storage/app/private`).

**Spec:** [docs/superpowers/specs/2026-10-31-phase-2-resources-design.md](../specs/2026-10-31-phase-2-resources-design.md)

## Global Constraints

- Work on a new branch `phase-2` off `main`. Commit after each task. **No `Co-Authored-By` trailer** (owner's rule).
- Item `type` ∈ `text | image | file`. Text/path values **encrypted at rest** (`encrypted` cast on `value`); `value` is in `$hidden`.
- Files on the **`local` (private) disk**, stored under `resources/` with Laravel's random hashed name; **never** a public URL. Allowed: image → png,jpg,jpeg,webp,gif; file → pdf,doc,docx,xls,xlsx,zip; max **10240 KB**.
- Only the super admin creates Resources and changes assignees. A user who can see a Resource may add an item; the item is auto-assigned to its creator (super admin sees everything anyway).
- Unassigned = invisible: no listing, no count, 404 on direct URL, no comments.
- Visibility also requires current project access: active project member (staff) or the project's client user (`user->client_id == project.client_id`, role `client`).
- Run tests via bash with a redirect: `php artisan test --filter=X > out.txt 2>&1; echo $?` then read `out.txt`. Use PowerShell for npm; no npx in Git Bash.
- Tests: Pest, `RefreshDatabase`, `$this->seed(RoleSeeder::class)` in `beforeEach` (see `tests/Feature/ProjectWorkspaceTest.php` for fixtures style).

## Review Focus

1. Removed/inactive project member who is still on an item's assignee list → must lose access (Task 2).
2. Assigning a user who isn't on the project (or its client) → rejected (Task 3).
3. Livewire tampering: passing another item's id to add-comment / download → 404, nothing written (Tasks 4, 5).
4. Hostile upload: wrong mime for the declared type (e.g. `.zip` as `image`), oversize, original filename containing `../` or HTML → rejected / stored under random name / escaped (Task 4).
5. Empty/whitespace text value, and very long value (> 10 000 chars) → validation error, not a 500 (Task 3).

---

### Task 1: Schema + models

**Files:**
- Create: `database/migrations/2026_10_31_000001_create_resources_tables.php`, `app/Models/Resource.php`, `app/Models/ResourceItem.php`
- Modify: `app/Models/Project.php` (add `resources(): HasMany`)
- Test: `tests/Feature/ResourceModelTest.php`

**Interfaces:**
- Produces: `Project::resources()`; `Resource` (`project_id,title,description`; `project()`, `items(): HasMany`); `ResourceItem` (`resource_id,type,label,value,original_name,created_by`; `resource()`, `creator()`, `assignees(): BelongsToMany` via `resource_item_user`, `comments(): MorphMany`); `$hidden=['value']`; `value` cast `encrypted`.

- [ ] **Step 1: Write failing tests** — `it('encrypts item value at rest')`: create project/resource/item `value='hunter2'`; assert `$item->fresh()->value === 'hunter2'` and `DB::table('resource_items')->value('value')` is not `'hunter2'` and doesn't contain `hunter2`. `it('hides value from toArray')`: `expect($item->toArray())->not->toHaveKey('value')`. `it('assigns users and comments')`: `$item->assignees()->attach($user)`; `$item->comments()->create(['author_id'=>$user->id,'body'=>'hi'])` → counts are 1.
- [ ] **Step 2: Run** `--filter=ResourceModelTest` → FAIL (class not found).
- [ ] **Step 3: Implement** migration (`resources`: id, foreignId project cascade, string title, text description nullable, timestamps; `resource_items`: id, foreignId resource cascade, string type, string label, text value nullable, string original_name nullable, foreignId created_by → users cascade, timestamps; `resource_item_user`: item id + user id cascade, composite primary — mirror `create_task_user_table`). Models per Interfaces; `value` in `$fillable`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: resources + resource items schema and models`

### Task 2: Visibility scopes

**Files:**
- Modify: `app/Models/ResourceItem.php`, `app/Models/Resource.php`
- Test: `tests/Feature/ResourceVisibilityTest.php`

**Interfaces:**
- Produces: `ResourceItem::scopeVisibleTo(Builder, User)`; `Resource::scopeVisibleTo(Builder, User)` (super admin: all; others: has ≥1 visible item).
- Rule for non-super-admin: assigned to the item **and** project access (client role → `projects.client_id = user->client_id`; otherwise active row in `project_members`).

- [ ] **Step 1: Write failing tests** (one project, one resource, items A assigned to staff user S, B unassigned, C assigned to client user K): 
  - `S` sees `[A]` only; `K` sees `[C]` only; super admin sees all 3; a staff user with no assignments sees none and `Resource::visibleTo` is empty for them; `S` sees the Resource, an unassigned member does not.
  - **Review-focus 1:** set `S`'s `project_members.active=false` → `ItemVisibleTo(S)` empty. Client K from a *different* client → empty.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** both scopes with `whereHas`; mirror the membership sub-join style in `Task::scopeVisibleTo`. Add a one-line `// ponytail:` comment if per-project section scope is ignored (it is: sections don't apply to resources).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: resource item visibility scopes`

### Task 3: Panel — list, create resource, add text item, assign

**Files:**
- Create: `app/Livewire/Resources/Panel.php`, `resources/views/livewire/resources/panel.blade.php`
- Modify: `resources/views/livewire/projects/show.blade.php` (embed `<livewire:resources.panel :project="$project" />` below the modules section, inside the left column), `resources/views/livewire/portal/project-view.blade.php` (embed at the bottom)
- Test: `tests/Feature/ResourcePanelTest.php`

**Interfaces:**
- Consumes: `ResourceItem::visibleTo`, `Resource::visibleTo`.
- Produces `Panel` (public `Project $project`; `mount(Project)` authorizes `view` (staff) or `viewAsClient` (clients) → 403 otherwise; computed `resources()` = `Resource::visibleTo($user)->where('project_id',…)->with(['items'=>fn($q)=>$q->visibleTo($user)->with(['assignees','creator'])])`).
  - `addResource()` — props `newTitle`, `newDescription`; super admin only (`abort_unless`), validates `title required|max:255`.
  - `addTextItem(int $resourceId)` — props `itemLabel[$resourceId]`, `itemValue[$resourceId]`; resource loaded via `Resource::visibleTo($user)->where('project_id', $this->project->id)->findOrFail`; validates `label required|max:255`, `value required|string|max:10000` (trimmed non-empty); creates item type `text`, `created_by = auth id`, attaches creator as assignee.
  - `setAssignees(int $itemId, array $userIds)` — super admin only; item via `ResourceItem::visibleTo`; every id must be an active project member or a user with `client_id == project.client_id` else `ValidationException`/abort 422; `sync`.
  - View: `x-ui.card` per Resource (title, description, items with type badge, label, value shown for text, created date + creator name, assignee chips; admin sees assignee multi-select + "+ Resource" form). Escape everything (`{{ }}`).

- [ ] **Step 1: Write failing tests**: admin adds Resource → row exists; non-admin `addResource` → 403; staff user S sees only item A's label in rendered HTML (`assertSee`/`assertDontSee` B's label); S `addTextItem` on visible Resource → item exists with `created_by` S and S in assignees, and admin-only B still invisible to S; S on a Resource they can't see → `ModelNotFoundException`/404; whitespace-only and 10 001-char value → validation errors, no row (**review-focus 5**); `setAssignees` by non-admin → 403; by admin with a user not on the project → error and pivot unchanged (**review-focus 2**); client K sees the panel on portal view with only C; unassigned/non-member `mount` → 403.
- [ ] **Step 2: Run** `--filter=ResourcePanelTest` → FAIL.
- [ ] **Step 3: Implement** `Panel` + view per Interfaces; follow `Projects\Show` conventions (authorize in each action, `$this->authorize`/`abort_unless`).
- [ ] **Step 4: Run** → PASS; also run `--filter=ProjectWorkspaceTest` and `ClientPortalTest` (embeds must not break them).
- [ ] **Step 5: Commit** `feat: resources panel — list, create, text items, assignees`

### Task 4: Image / file items + private download

**Files:**
- Create: `app/Http/Controllers/ResourceItemDownloadController.php`
- Modify: `app/Livewire/Resources/Panel.php` (+ `WithFileUploads`), `panel.blade.php`, `routes/web.php`
- Test: `tests/Feature/ResourceFileTest.php`

**Interfaces:**
- Produces route `resource-items.download` = `GET resource-items/{item}/download` (auth middleware); controller `__invoke(int $item)`: `ResourceItem::visibleTo(auth user)->whereIn('type',['image','file'])->findOrFail($item)`; stream from `Storage::disk('local')` — `image` inline via `->response($path, $original_name)`, `file` as `->download($path, $original_name)`.
- Panel: `addFileItem(int $resourceId, string $type)` with `upload[$resourceId]` (`WithFileUploads`); rules: `type in:image,file`; image → `mimes:png,jpg,jpeg,webp,gif|max:10240`, file → `mimes:pdf,doc,docx,xls,xlsx,zip|max:10240`; `store('resources','local')`; item `value` = stored path, `original_name` = `basename` of client name; label required; creator auto-assigned. Resource scoped like `addTextItem`. Image items render `<img src="route(...)">`; files render a link.

- [ ] **Step 1: Write failing tests** (`Storage::fake('local')`, `UploadedFile::fake()`): staff user uploads png as `image` → item row with stored path, file exists on `local`, **not** on `public`; assigned user GET download → 200 and file content; unassigned user → 404; guest → redirect to login; **review-focus 4:** `.zip` as `image` → validation error, no item; 11 MB file → error; client filename `../../evil<script>.pdf` → stored path is under `resources/` with hashed name, `original_name` has no slashes, and rendered page doesn't contain a raw `<script>`; **review-focus 3:** download for a text item id → 404.
- [ ] **Step 2: Run** `--filter=ResourceFileTest` → FAIL.
- [ ] **Step 3: Implement** controller, route (inside the existing `auth` group), panel upload action and view bits.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: resource image/file items with permission-checked download`

### Task 5: Comments on items

**Files:**
- Modify: `app/Livewire/Resources/Panel.php`, `panel.blade.php`
- Test: `tests/Feature/ResourceCommentTest.php`

**Interfaces:**
- Produces `Panel::addComment(int $itemId)` with `commentBody[$itemId]`; item via `ResourceItem::visibleTo($user)->whereHas('resource', fn($q)=>$q->where('project_id',$this->project->id))->findOrFail`; validates `required|string|max:2000`; `$item->comments()->create(['author_id'=>$user->id,'body'=>…])`. View lists each visible item's comments (author name, time, escaped body) + a one-line form. No edit/delete.

- [ ] **Step 1: Write failing tests**: assigned user comments → 1 comment with right author; admin can comment on any item; unassigned user `addComment(itemId)` → 404 and `Comment::count()===0` (**review-focus 3**); comment on an item from *another project* via this project's panel → 404; empty body → validation error; comment bodies appear only on the rendered panel of assigned users (`assertDontSee` for unassigned).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: comments on resource items`

### Task 6: Full-suite check + docs

**Files:**
- Modify: `docs/STATUS.md` (Phase 2 done, test count, link to this plan/spec, update "What's next"), `database/seeders/DemoSeeder.php` (one demo Resource with a text item assigned to the admin + demo client user, keeping idempotency like the existing seeder)

- [ ] **Step 1:** Run the whole suite: `php artisan test > out.txt 2>&1; echo $?` → exit 0; record the count.
- [ ] **Step 2:** `php artisan db:seed --class=DemoSeeder` twice → no duplicate Resource rows.
- [ ] **Step 3:** Update `docs/STATUS.md`.
- [ ] **Step 4: Commit** `docs: phase 2 resources complete`

---

## Self-review

- **Spec coverage:** container (T1,3), typed items (T1,3,4), encrypted values (T1), private files + permission-checked download (T4), per-item assignment, admin-only (T3), unassigned invisible incl. 404 (T2–5), comments reusing `Comment` (T5), client portal access and cross-client isolation (T2,3), who-can-add rule (T3). Non-goals not planned.
- **Types:** `visibleTo` scope name, `Panel` method names, and route name are defined once (T2/T3/T4) and reused as-is in later tasks.
- **Proportion:** tasks give signatures, rules and assertion lists; bodies are left to the implementer.
