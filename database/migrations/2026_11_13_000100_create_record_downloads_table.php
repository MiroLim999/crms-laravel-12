<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which records have been taken out of the archive as a CSV or Excel file.
 *
 * A separate table, not columns on records: a submitted record's own row never
 * changes (corrections go through change requests), and this keeps every
 * download, not just the latest. A record with no row here has never been
 * downloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('records')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 8);
            $table->timestamp('downloaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['record_id', 'downloaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_downloads');
    }
};
