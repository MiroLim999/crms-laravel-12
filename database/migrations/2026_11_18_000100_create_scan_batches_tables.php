<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch kept on the server while it is worked on, so a reload, a closed tab
 * or a crash loses no more than the page being worked on.
 *
 * The batch holds its queue (one row per page: where it came from, its state,
 * its alignment and flags) and each record's draft (what was typed and ticked
 * in Verify and Review & Export). The original files of pages not queued yet
 * stay on the user's PC: on resume the user picks them again, matched by their
 * fingerprint (source_hash). Pages already queued are on the server already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 80);
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('page_two_template_id')->nullable()
                ->constrained('document_templates')->nullOnDelete();
            // The workspace's own name for the batch (its queued files' folder).
            $table->string('batch_key', 64)->unique();
            $table->string('status', 16)->default('open');
            // Each change raises it: a change from an older copy (another tab) is refused.
            $table->unsignedInteger('version')->default(0);
            // The tab working on it now, and when it was last heard from.
            $table->string('tab', 64)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('scan_batch_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_batch_id')->constrained()->cascadeOnDelete();
            // The workspace's own name for the page, stable while the batch lasts.
            $table->string('client_key', 64);
            $table->unsignedInteger('position');
            $table->string('source_name');
            $table->char('source_hash', 64)->nullable();
            $table->unsignedInteger('page_no')->default(1);
            $table->unsignedInteger('page_total')->default(1);
            $table->string('record_key', 120);
            $table->string('status', 16);
            $table->foreignId('document_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workspace_scan_path', 500)->nullable();
            $table->json('alignment')->nullable();
            $table->json('flags')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('record_id')->nullable()->constrained('records')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scan_batch_id', 'client_key']);
        });

        Schema::create('scan_batch_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_batch_id')->constrained()->cascadeOnDelete();
            $table->string('record_key', 120);
            $table->json('draft');
            $table->timestamps();

            $table->unique(['scan_batch_id', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_batch_drafts');
        Schema::dropIfExists('scan_batch_pages');
        Schema::dropIfExists('scan_batches');
    }
};
