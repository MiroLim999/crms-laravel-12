# Civil Registry Management System (CRMS)

A Laravel 12 system for digitizing and managing civil registry documents (birth, death,
and marriage certificates). Staff scan handwritten certificates, a fine-tuned TrOCR model
extracts the field values, staff verify and submit them, and the resulting records become
a searchable, locked archive with a legally meaningful audit trail.

## Roles

Three seeded roles: **Staff**, **Admin**, **Super Admin**.

**There is no public self-registration.** Admins create all accounts. Never scaffold a
public register route, controller, or view.

| Capability                            | Staff | Admin | Super Admin |
|---------------------------------------|-------|-------|-------------|
| Upload & process documents             | Yes   | No    | Yes         |
| Verify & submit extracted records      | Yes   | No    | Yes         |
| Search / view records archive          | Yes   | Yes   | Yes         |
| Request changes to locked records      | Yes   | No    | Yes         |
| Approve / reject change requests       | No    | Yes   | Yes         |
| Analytics dashboard                    | No    | Yes   | Yes         |
| Manage user accounts & roles           | No    | Yes   | Yes         |
| View audit log                         | No    | Yes   | Yes         |
| Generate reports                       | No    | Yes   | Yes         |
| Document template builder              | No    | No    | Yes         |
| OCR model management (and related)     | No    | No    | Yes         |

Super Admin OCR model management is a **single page** covering the whole model lifecycle —
list, add, rename, delete, rescan, set active, engine status, evaluation metrics — mirroring
the legacy prototype. See `trocr-service.md` for the full spec.

## Separation of duties (do not "simplify" this)

**Admin cannot edit record values.** Data entry belongs to Staff. Corrections to locked
records go through the change-request flow: Staff requests, Admin approves or rejects.
Admin handles people and oversight, not data.

This constraint is intentional and keeps the audit trail legally meaningful. Do not grant
Admin write access to records for convenience, and do not add an "Admin quick edit"
shortcut, even if it looks like an obvious improvement.

Derived rules to follow unless told otherwise:
- Only Super Admin creates Admin accounts. Admin user management covers Staff accounts.
- No one can edit or delete a Super Admin account other than a Super Admin.
- Every state change on a record, change request, account, or model action writes an
  audit log entry with actor, action, target, before/after values, and timestamp.

## Authentication

- **Seeded Super Admin** (must exist after `db:seed`):
  - username / email: `superadmin@admin.com`
  - password: `superadmin@admin.com`
- Super Admin creates Admin and Staff accounts and generates a **temporary password**.
- A user with a temporary password is **forced to change it on first login**. Block all
  other routes via middleware until the password is changed.
- Admin and Staff can change their own password in account settings.
- Password changes and account creation are audit-logged.

## Record lifecycle

1. Staff uploads a scanned certificate.
2. Fields are marked/placed on the scan (document templates drive default field boxes).
3. The TrOCR service returns extracted text plus a per-field confidence score.
4. Staff reviews low-confidence fields, corrects them, and submits.
5. The submitted record is **locked** and enters the searchable archive.
6. Further changes require a change request approved by Admin or Super Admin.

Confidence is the model's certainty in its own output, not accuracy. Surface it as a
review flag (e.g. highlight fields under 80%), never as a quality guarantee.
