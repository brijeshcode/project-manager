# Phase 2 — Resources: design

_Supersedes the "Resources" section of the 2026-10-26 design spec (which had three fixed types and a share list per resource). Decided with the owner on 2026-10-31._

## Intent
One place per project for the things the agency keeps and shares: logins, files, screenshots, notes. The owner decides, item by item, exactly who can see each one.

## Model
- **Resource** — belongs to a Project. Title, description. Works like a Module: a container.
- **ResourceItem** — belongs to a Resource. Fields: `type` (`text` | `image` | `file`, extensible), `label`, `value`, `created_by` (user), `created_at`.
  - `text`: `value` is the text, **encrypted at rest** (Laravel `encrypted` cast). Any text item may hold a password.
  - `image` / `file`: `value` is a path on **private** storage (not `public`). Downloads go through a controller that checks access. Validate mime/size on upload.
- **Assignees** — `resource_item_user` pivot. **Access is per item.** Only assigned users plus the super admin can see/download an item.
- **Comments** — reuse the existing polymorphic `Comment` model (`commentable`) on ResourceItem. Plain text, no edit/delete. Readable/writable only by users who can view the item.

## Access rules
- Super admin: everything.
- A user sees a Resource in a project only if assigned to ≥1 of its items; they see only their items. Title/description of the container are visible to them.
- Not assigned = the item does not exist for that user (no listing, no count, 404 on direct URL).
- Only the super admin changes assignees.
- Clients: same rules via the portal. A client sees only items assigned to them. Cross-client isolation from Phase 1 still applies.
- Credentials/text values are never logged or put in flash/error output; decrypted only when an authorized user views the item.

## Who can add items (builder/owner call — confirm)
Super admin can add items to any Resource. A user who can already see a Resource (assigned to one of its items) can add an item to it; the new item is auto-assigned to its creator and the super admin, and nobody else until the admin assigns more. This covers "client uploads a logo" without giving clients any sharing power.

## Goals
- Per-item assignment that cannot leak: lists, counts, downloads, comments all go through one policy / visibility scope (`ResourceItem::visibleTo($user)`), mirroring `Task::visibleTo`.
- Encrypted text values; private file storage.
- Reuse existing UI kit, Comment model, project workspace patterns.

## Non-goals
- Per-container sharing, folders/nesting, versioning, expiry of access, audit log, comment edit/delete, file previews beyond images.
- Multi-tenancy (Phase 5).

## Anti-goals (failure even if it works)
- Any path where an unassigned user can read a value, file, comment, or learn an item exists.
- Plaintext secrets in the DB, logs, or a public storage URL.

## Left to the builder
Table/column names, upload size limits and allowed mimes (suggest: images png/jpg/webp/gif, files pdf/doc/xls/zip, 10 MB), UI layout (Resources tab on the project page, cards matching the existing kit), policy method names. Tests in Pest, test-first; every access rule above gets a test (assigned / unassigned / client / super admin, for view, download, comment, list).
