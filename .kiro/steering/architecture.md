# Backend architecture

Where code belongs under `app/`, `database/`, and `tests/`. The view layer has its own
rules in `views.md`; stack and tooling live in `tech.md`.

## Directory map

```
app/
├── Enums/              fixed value sets + the behaviour that belongs to them
├── Http/
│   ├── Controllers/    HTTP plumbing only. Thin.
│   │   └── Auth/       sign-in and forced password change
│   ├── Middleware/     cross-cutting request guards
│   └── Requests/       Form Request validation classes
├── Models/             Eloquent models. Relations, casts, state predicates.
├── Providers/          bootstrapping. AuthServiceProvider holds the gates.
├── Services/           domain operations that change state
│   └── Ocr/            everything that talks to the FastAPI service
└── Support/            framework-adjacent helpers with no domain logic
```

## Layer responsibilities

**Controller** — resolve the request, authorize, delegate, return a response. No
business rules, no multi-step transactions. If a method grows past roughly 20 lines of
logic, the logic belongs in a Service.

**Service** — a domain operation with rules, usually spanning several tables and
wrapped in a transaction. Services own the audit entry for what they do.
State-changing: `UserProvisioner`, `ChangeRequestService`, `Ocr\OcrModelManager`.
Infrastructure: `AuditLogger`, `Ocr\OcrClient`.
Read-only helper that lives here for cohesion: `Ocr\EvaluationCharts`.

**Model** — relations, casts, and predicates about its own state (`isLocked()`,
`hasPendingChangeRequest()`, `needsReview()`). No cross-aggregate orchestration.

**Enum** — a fixed set plus the behaviour that travels with it: labels, badge classes,
and role capability predicates. `RoleSlug::canEnterData()` lives here, not in a helper.

**Support** — helpers with no domain meaning. `Navigation` builds the sidebar from
gates. If a class starts encoding business rules, move it to `Services/`.

## Authorization lives in exactly one place

All **14** gates are defined in `AuthServiceProvider` — the 11 abilities from the
capability matrix in `product.md`, plus three derived ones for user management
(`users.manage-role`, `users.update`, `users.deactivate`). That file is the single
authoritative translation of the matrix.

A `Gate::before` hook denies everything to a deactivated account, whatever its role.

- Routes guard with `->middleware('can:ability')`.
- Controllers guard with `$this->authorize(...)` for per-model checks.
- Views hide with `@can`, which is presentation only and never the security boundary.
- Role predicates funnel through `User::hasRole()` / `RoleSlug`. **Never compare role
  slugs as raw strings anywhere else.**

There is deliberately no ability that lets Admin write record values. Do not add one.

## Auditing: who writes the entry

Everything goes through `Services\AuditLogger` — never `AuditLog::create()` directly.

The rule for *where* the call sits:

- **A Service owns the audit entry when it owns the operation.** If the change involves
  several tables or a transaction, the Service logs it. `UserProvisioner` and
  `ChangeRequestService` do this.
- **A controller may log directly for a single-step action with no Service.** Sign-in,
  sign-out, a password change, a report export. These have nothing to orchestrate.

Use `saveAndLog()` for updates to an existing model — it takes the diff *before*
saving, because Eloquent re-syncs originals during `save()` and the before values are
otherwise unrecoverable. Plain `log()` is for creations and events.

`AuditLog` is append-only and the model throws on update or delete. Never work around
that.

## Validation

**Use a Form Request when either is true:**
- Authorization depends on the payload (e.g. which role is being granted)
- The rules are non-trivial or shared between store and update

**Inline `$request->validate()` is fine for:**
- Read-side filters (index pages, report parameters)
- Single-purpose forms with a handful of simple rules

Current Form Requests: `StoreUserRequest`, `UpdateUserRequest` (both decide whether the
actor may grant the requested role, in `after()`), and `LoginRequest` (owns throttling).
Everything else validates inline, which is correct for its complexity.

Put payload-dependent authorization in the Form Request's `after()` hook, not the
controller — that keeps the rule next to the rules it depends on.

## Models

- Cast enums, dates, booleans, and JSON in `casts()`. Never hand-parse in a controller.
- Eager load with `with()` on anything rendered in a loop. N+1 in a paginated table is
  the most common performance bug here.
- Use Eloquent. No string-interpolated SQL.
- Deactivate rather than delete anything an audit entry can point at. `users` has
  `is_active` for exactly this reason, and there is no `users.destroy` route.
- Avoid static memoisation of database rows. A cached model instance survives
  transaction rollback and hands out primary keys that no longer exist — this broke
  `Role::of()` and every test in the suite.

## Database

**Migrations** are ordered by filename and foreign keys make that ordering load-bearing:

- `0001_01_01_*` — foundation: roles, users, cache, jobs, audit_logs
- `2026_01_01_*` — domain: templates, records, change requests, ocr models

`create_roles_table` must sort before `create_users_table` because users has a FK to
roles. Keep new domain migrations in the `2026_*` range.

**Seeders** — `DatabaseSeeder` runs only what a fresh install genuinely needs: roles,
the bootstrap Super Admin, starter templates. Anything for local convenience stays
out of it and is run explicitly, like `DemoUsersSeeder`.

**Factories** default to the common case and expose named states for variations:
`->staff()`, `->admin()`, `->withTemporaryPassword()`, `->submitted()`,
`->lowConfidence()`. Add a state rather than passing raw attribute arrays in tests.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Model | singular noun | `CivilRecord`, `RecordField` |
| Controller | singular subject + `Controller` | `UserController` |
| Service | agent noun or `<Domain>Service` | `UserProvisioner`, `ChangeRequestService` |
| Enum | singular, describes the set | `RecordStatus`, `RoleSlug` |
| Form Request | `<Verb><Model>Request` | `StoreUserRequest` |
| Audit action | `subject.past_tense_verb` | `record.submitted`, `user.deactivated` |

`CivilRecord` is named that way, not `Record`, because the codebase also discusses
audit records and change records. Its table is still `records`.

## Tests

Feature tests over unit tests — this app's risk is in HTTP flow and authorization, not
in isolated functions. Use `RefreshDatabase` and the real MySQL `crms_test` database.

`CapabilityMatrixTest` is load-bearing. It asserts the permission table route by route
and ability by ability. **If a change makes it fail, the change is wrong, not the test.**

Every new feature that changes state needs tests for: the gate denying the wrong role,
the happy path, and the audit entry being written.

## Invariants — never break these

1. No route may edit or delete a submitted record. Corrections go through an approved
   change request, and approval is what applies the values.
2. Admin has no data-entry ability, and no route into `DocumentScanController`.
3. Audit entries are never updated or deleted.
4. No public registration route.
5. Accounts are deactivated, never deleted.
6. The FastAPI service is called server-side only and stays bound to `127.0.0.1`;
   authorization happens in Laravel because the service has none.
