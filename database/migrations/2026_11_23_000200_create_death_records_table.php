<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Death register entries, stored as their own table so each certificate's
 * fields are typed columns instead of key/value rows.
 *
 * Runs alongside the existing EAV `records` / `record_fields` tables; the
 * document-type (birth/death/marriage) still lives on `CivilRecord::doc_type`
 * for scanning, while this table holds the typed death data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('death_records', function (Blueprint $table) {
            $table->id();

            // Registry reference as written on the certificate.
            $table->string('book_no', 32);
            $table->string('page_no', 32);
            $table->string('reg_no', 64);

            $table->string('name', 255);
            // Stored verbatim from the certificate (e.g. "12 Jun 1995").
            $table->string('date_of_death', 64);
            $table->string('sex', 16);
            $table->date('date_of_reg')->nullable();

            // Age at death; any component may be unknown on the certificate.
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->unsignedSmallInteger('age_days')->nullable();
            $table->unsignedSmallInteger('age_hours')->nullable();
            $table->unsignedSmallInteger('age_mins')->nullable();

            $table->string('civil_status', 64)->nullable();
            $table->string('nationality', 255)->nullable();
            $table->string('place_of_death', 255);
            $table->string('cause_of_death', 255)->nullable();
            $table->string('corpse_disposal', 255)->nullable();
            $table->string('attendant', 255)->nullable();
            $table->string('residence', 255)->nullable();

            $table->date('date_of_birth')->nullable();

            $table->boolean('late_registration')->default(false);

            $table->timestamps();

            $table->index('reg_no');
            $table->index(['book_no', 'page_no']);
            $table->index('date_of_reg');
            $table->index('late_registration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('death_records');
    }
};
