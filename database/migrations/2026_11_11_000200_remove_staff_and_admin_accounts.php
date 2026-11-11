<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRMS now runs with one account, the Super Admin (docs/SUPER-ADMIN-ONLY-PLAN.md).
 *
 * Moves everything the other accounts own to the Super Admin, deletes those accounts,
 * then deletes the Staff and Admin roles. Plain queries only: the models no longer
 * know the Staff and Admin role names.
 *
 * There is no way back in code. Restore the database backup taken before running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('users')->exists()) {
            // A fresh install: only drop the unused roles.
            DB::table('roles')->whereIn('slug', ['staff', 'admin'])->delete();

            return;
        }

        $superAdminRole = DB::table('roles')->where('slug', 'super_admin')->value('id');
        $keep = DB::table('users')
            ->where('role_id', $superAdminRole)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($keep === null) {
            throw new RuntimeException('No Super Admin account exists, so the other accounts were not removed. Run the SuperAdminSeeder first.');
        }

        DB::transaction(function () use ($keep): void {
            $ids = DB::table('users')->where('id', '!=', $keep->id)->pluck('id')->all();

            if ($ids !== []) {
                // Records and change requests forbid deleting their author, so they move first.
                foreach ([
                    ['records', 'created_by'],
                    ['records', 'submitted_by'],
                    ['change_requests', 'requested_by'],
                    ['change_requests', 'reviewed_by'],
                ] as [$table, $column]) {
                    DB::table($table)->whereIn($column, $ids)->update([$column => $keep->id]);
                }

                // The database clears every other link by itself: audit entries keep the
                // person's name, unsaved scan pages go, page runs lose the name.
                DB::table('users')->whereIn('id', $ids)->delete();
            }

            DB::table('roles')->whereIn('slug', ['staff', 'admin'])->delete();

            // The account most likely still uses the default password from config/crms.php.
            DB::table('users')->where('id', $keep->id)->update(['must_change_password' => true]);

            if ($ids !== []) {
                DB::table('audit_logs')->insert([
                    'user_id' => $keep->id,
                    'actor_name' => $keep->name,
                    'actor_role' => 'super_admin',
                    'action' => 'users.removed',
                    // Account ids only: the old entries already carry each person's name.
                    'description' => 'CRMS now has one account. Removed '.count($ids).' other account(s). '
                        .'Their records were moved to '.$keep->name.'.',
                    'old_values' => json_encode(['account_ids' => $ids]),
                    'new_values' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Deleted accounts cannot be rebuilt. Restore the backup instead.
    }
};
