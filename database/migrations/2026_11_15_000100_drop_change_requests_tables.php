<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections (change requests) are gone: a saved record's values are edited
 * in Review & Export, which writes each old and new value to the audit log
 * (record.edited). The audit log keeps its old change_request.* entries.
 *
 * Rolling back brings the two tables back empty; the corrections dropped
 * here are not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('change_request_items');
        Schema::dropIfExists('change_requests');
    }

    public function down(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['record_id', 'status']);
        });

        Schema::create('change_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_field_id')->constrained()->cascadeOnDelete();
            $table->text('current_value')->nullable();
            $table->text('proposed_value')->nullable();
            $table->timestamps();

            $table->unique(['change_request_id', 'record_field_id']);
        });
    }
};
