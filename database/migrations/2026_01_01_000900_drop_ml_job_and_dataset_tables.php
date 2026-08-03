<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the fine-tuning and dataset registries.
 *
 * Training, evaluation, dataset preparation, and batch prediction are command-line
 * work under ml/ and are no longer driven from the web UI, so nothing in the
 * application reads these tables. `MlJob`, `MlDataset`, and their enums are gone
 * with them.
 *
 * The migrations that created them are deliberately left in place above rather than
 * deleted. Migrations are an append-only ledger: editing or removing one that has
 * already run leaves rows in the `migrations` table pointing at files that no longer
 * exist and breaks `migrate:rollback` for every database that applied them. A fresh
 * install pays four wasted statements - it creates these two tables and drops them
 * again - which is a fair price for a history that replays.
 *
 * Not reversible. See `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No foreign key between the two, and nothing references either of them -
        // both only point outward at `users` - so the order is cosmetic.
        Schema::dropIfExists('ml_jobs');
        Schema::dropIfExists('ml_datasets');
    }

    /**
     * Deliberately empty.
     *
     * Recreating the columns would not recreate the run history that was in them,
     * so a `down()` here would hand back an empty shell and call it a rollback. If
     * the schema is genuinely wanted again, the create migrations above still
     * describe it exactly - check out a commit before this one instead.
     */
    public function down(): void
    {
        //
    }
};
