<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marriage register entries, stored as their own table so each certificate's
 * fields are typed columns instead of key/value rows.
 *
 * Runs alongside the existing EAV `records` / `record_fields` tables; the
 * document-type (birth/death/marriage) still lives on `CivilRecord::doc_type`
 * for scanning, while this table holds the typed marriage data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marriage_records', function (Blueprint $table) {
            $table->id();

            // Registry reference as written on the certificate.
            $table->string('book_no', 32);
            $table->string('page_no', 32);
            $table->string('reg_no', 64);

            $table->string('husband_name', 255);
            $table->string('wife_name', 255);
            // Stored verbatim from the certificate (e.g. "12 Jun 1995").
            $table->string('date_of_marriage', 64);
            $table->unsignedSmallInteger('husband_age')->nullable();
            $table->unsignedSmallInteger('wife_age')->nullable();

            $table->string('husb_nationality', 255)->nullable();
            $table->string('wife_nationality', 255)->nullable();
            $table->string('husb_civil_status', 64)->nullable();
            $table->string('wife_civil_status', 64)->nullable();
            $table->string('husb_mother', 255)->nullable();
            $table->string('wife_mother', 255)->nullable();
            $table->string('husb_father', 255)->nullable();
            $table->string('wife_father', 255)->nullable();

            $table->date('date_of_reg');
            $table->string('place_of_marriage', 255);
            $table->string('ceremony_type', 64)->nullable();

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
        Schema::dropIfExists('marriage_records');
    }
};
