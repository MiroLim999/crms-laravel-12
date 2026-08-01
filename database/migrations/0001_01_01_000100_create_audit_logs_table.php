<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail.
 *
 * Every state change on a record, change request, account, or OCR model writes a
 * row here with actor, action, target, and before/after values. This is what makes
 * the separation of duties between Staff and Admin legally meaningful, so entries
 * are never updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Actor. Nullable so system-generated events are still recordable, and
            // nullOnDelete so removing a user never erases their history.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalised actor details, kept so the log still reads correctly
            // after an account is renamed or deactivated.
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 32)->nullable();

            // e.g. record.submitted, change_request.approved, user.created, ocr_model.deleted
            $table->string('action', 64)->index();

            // Polymorphic target of the action.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->string('description')->nullable();

            // Before/after snapshots for value changes.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
