<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of every fine-tuning and evaluation run.
 *
 * The FastAPI service owns a live job because it owns the GPU, and its state is
 * only in memory. This table is the durable, audit-friendly record: what was run,
 * with which hyperparameters, against which dataset, by whom, and how it ended.
 *
 * Laravel polls the service and mirrors into these rows. While a job is live the
 * service is the source of truth; once it is terminal, this row is all that is
 * left, which is the point.
 *
 * Note this is `ml_jobs`, not Laravel's own `jobs` queue table - unrelated things
 * that unfortunately share a word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_jobs', function (Blueprint $table) {
            $table->id();

            // The service's own job id. Unique, and the key used when polling.
            $table->string('job_id')->unique();

            $table->string('type');     // MlJobType
            $table->string('status');   // MlJobStatus

            // The hyperparameters actually used, not the ones requested, so a run
            // can be reproduced from this row alone.
            $table->json('config')->nullable();

            // Denormalised out of config because these three are what the history
            // table is filtered and read by.
            $table->string('dataset')->nullable();
            $table->string('model_key')->nullable();
            $table->string('output_name')->nullable();

            $table->json('progress')->nullable();
            $table->json('metrics')->nullable();

            // Tail of the service log, kept for failures. Never trimmed away on
            // success either: it is the only record of what the run actually did.
            $table->json('log')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_jobs');
    }
};
