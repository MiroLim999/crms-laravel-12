make a Laravel 12 project name Civil Registry Management System (CRMS) use SNEAT admin design.

## Roles

Three seeded roles. **No public self-registration** — admins create all accounts but super admin is must be seeded : username: superadmin@admin.com pass: superadmin@admin.com 

| Capability                        | Staff | Admin | Super Admin |
|-----------------------------------|-------|-------|-------------|
| Upload & process documents        | Yes   | No    | Yes         |
| Verify & submit extracted records | Yes   | No    | Yes         |
| Search / view records archive     | Yes   | Yes   | Yes         |
| Request changes to locked records | Yes   | No    | Yes         |
| Approve / reject change requests  | No    | Yes   | Yes         |
| Analytics dashboard               | No    | Yes   | Yes         |
| Manage user accounts & roles      | No    | Yes   | Yes         |
| View audit log                    | No    | Yes   | Yes         |
| Generate reports                  | No    | Yes   | Yes         |
| Document template builder         | No    | No    | Yes         |
| OCR model management and stuffs related              | No    | No    | Yes         |


**Admin cannot edit record values.** Data entry belongs to Staff; corrections go through
the change-request flow. Admin does people and oversight. This is intentional — it keeps
the audit trail legally meaningful. Do not "simplify" by granting Admin write access.


auth:

-superadmin can create both admin and staff account and generate temporary password and when loggined it will force to change password.

-both admin and staff can change password in their account settings.

tech to use:
-laravel 12
-SNEAT admin
-bootstrap & tailwind
-Mysql

for the TrOcr:
-FAST API
-python
