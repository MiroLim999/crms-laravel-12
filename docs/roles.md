# Roles and permissions

CRMS has three fixed roles, and every account has exactly one. This page says what each role may do.

The rules are in [AuthServiceProvider.php](../app/Providers/AuthServiceProvider.php), one "ability" per row of the table below, and the roles are in [RoleSlug.php](../app/Enums/RoleSlug.php). [CapabilityMatrixTest.php](../tests/Feature/CapabilityMatrixTest.php) checks every row, both the ability and the pages it guards. If a rule changes, change this page, the provider and the test together.

## The roles

| Role | What it is for |
|---|---|
| **Staff** | Digitizes documents, verifies extracted records, and requests corrections. |
| **Admin** | Oversees people and approvals. Cannot edit record values. |
| **Super Admin** | Full access, including template building and OCR model management. |

## What each role can do

| Capability | Staff | Admin | Super Admin | Ability | Routes it guards |
|---|:-:|:-:|:-:|---|---|
| Scan, verify and submit records | Yes | No | Yes | `documents.process` | `documents.create`, `documents.workspace`, `documents.store`, `documents.pages.*` |
| Request a change to a submitted record | Yes | No | Yes | `change-requests.create` | `records.change-requests.create`, `records.change-requests.store` |
| Search and view the records archive | Yes | Yes | Yes | `records.view` | `records.index`, `records.show`, `records.scan`, `records.page-image` |
| Approve or reject change requests | No | Yes | Yes | `change-requests.moderate` | `change-requests.approve`, `change-requests.reject` |
| See analytics (on the Dashboard) | No | Yes | Yes | `analytics.view` | `analytics.index`, an old address that now opens the Dashboard |
| Generate and export reports | No | Yes | Yes | `reports.generate` | `reports.index`, `reports.export` |
| Manage user accounts | No | Yes | Yes | `users.manage` | `users.*` (see [Account rules](#account-rules)) |
| View the audit log | No | Yes | Yes | `audit.view` | `audit.index` |
| Build document templates | No | No | Yes | `templates.manage` | `templates.*` |
| Manage OCR models and check the OCR service | No | No | Yes | `ocr.manage` | `ocr.*`, `dashboard.system-status` |

Every signed-in account can also:

- open the Dashboard, which shows analytics to Admin and Super Admin and a personal overview to Staff;
- change its own password in Settings;
- open the change requests list, where Staff see only their own requests and Admin and Super Admin see all of them;
- open a change request it made, and withdraw it while it is pending. Admin and Super Admin can open any request.

The sidebar menu is built from the same abilities, so nobody sees a link they can't follow. Hiding a link is only a convenience: every route checks its ability itself.

## Account rules

There is no sign-up page. The first Super Admin is created by the database seeder (`SuperAdminSeeder`), and every other account is created by an Admin or a Super Admin.

| Action | Super Admin | Admin | Ability |
|---|---|---|---|
| Create an account | With any role | Staff accounts only | `users.manage-role` |
| Edit an account, reset its password or reactivate it | Any account | Staff accounts only | `users.update` |
| Deactivate an account | Any account except their own | Staff accounts only | `users.deactivate` |

A deactivated account can do nothing, whatever its role. Accounts are deactivated, never deleted, so the audit log always points at a real person.

## Deliberately missing

No ability lets an Admin enter or edit record values. Data entry belongs to Staff and Super Admin, and a submitted record changes only through an approved change request. Don't add one: this separation of duties is what makes the audit log meaningful.
