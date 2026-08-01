<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The correction path for locked records.
 *
 * This table is the mechanism that keeps the audit trail meaningful: Staff cannot
 * edit a submitted record, and Admin cannot edit record values at all. A change
 * only lands when Staff propose it and Admin (or Super Admin) approve it, and the
 * proposal, the decision, and the applied values are all recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();

            // pending -> approved | rejected | withdrawn
            $table->string('status', 16)->default('pending');

            $table->text('reason');

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

            // Snapshot of the value at proposal time, so a reviewer sees exactly
            // what the requester was looking at.
            $table->text('current_value')->nullable();
            $table->text('proposed_value')->nullable();

            $table->timestamps();

            $table->unique(['change_request_id', 'record_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_items');
        Schema::dropIfExists('change_requests');
    }
};
