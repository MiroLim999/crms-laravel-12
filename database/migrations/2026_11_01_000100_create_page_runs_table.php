<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for every Detect or Scan the page job finishes, fails or has cancelled.
 *
 * document_pages cannot answer "how long do scans take" or "how many failed":
 * a cancelled page is deleted at once and every other unsubmitted page after
 * a day. This table is the permanent, personal-data-free record the dashboard
 * reads. It holds no registry text and no image, and its page id points at nothing once the page is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // The page may be gone by the time anyone looks, so no foreign key.
            $table->unsignedBigInteger('document_page_id')->nullable();

            // full | detect | read (see ProcessDocumentPage::MODE_*)
            $table->string('mode', 16);
            // completed | failed | cancelled
            $table->string('outcome', 16);

            // Null when it never started (cancelled while waiting) or the worker died.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('lines')->nullable();
            $table->string('ocr_model_key')->nullable();
            $table->string('error', 500)->nullable();

            // When it ended.
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_runs');
    }
};
