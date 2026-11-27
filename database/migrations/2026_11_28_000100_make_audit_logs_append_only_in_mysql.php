<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log is append-only in the database itself, not only in the app
 * (AuditLog's model guard): MySQL refuses every UPDATE and DELETE on it, from
 * any account and any tool. Someone with the app's database password still
 * cannot rewrite or erase the trail.
 *
 * Only an account with the TRIGGER privilege can drop these. In Docker that is
 * the owner account, which the running app never uses (secure-database.sh).
 *
 * MySQL does not fire triggers for foreign-key actions, so deleting a user
 * still sets audit_logs.user_id to NULL as before; the entry itself stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->dropTriggers();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: entries cannot be changed'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: entries cannot be deleted'
            SQL);
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
