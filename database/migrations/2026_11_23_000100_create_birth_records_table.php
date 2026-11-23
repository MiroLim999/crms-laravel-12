<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Birth register entries, stored as their own table so each certificate's
 * fields are typed columns instead of key/value rows.
 *
 * Runs alongside the existing EAV `records` / `record_fields` tables; the
 * document-type (birth/death/marriage) still lives on `CivilRecord::doc_type`
 * for scanning, while this table holds the typed birth data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birth_records', function (Blueprint $table) {
            $table->id();

            // Registry reference as written on the certificate.
            $table->string('book_no', 32);
            $table->string('page_no', 32);
            $table->string('reg_no', 64);

            $table->string('name', 255);
            // Stored verbatim from the certificate (e.g. "12 Jun 1995").
            $table->string('date_of_birth', 64);
            $table->string('sex', 16);
            $table->date('date_of_reg');
            $table->string('place_of_birth', 255);

            $table->string('name_of_mother', 255);
            $table->string('nationality_mother', 255)->nullable();
            $table->string('name_of_father', 255);
            $table->string('nationality_father', 255)->nullable();

            $table->date('parents_marriage_date')->nullable();
            $table->string('parents_marriage_place', 255)->nullable();

            $table->string('attendant', 255)->nullable();
            $table->string('type_of_birth', 64)->nullable();
            $table->unsignedSmallInteger('mother_age')->nullable();
            $table->unsignedSmallInteger('father_age')->nullable();

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
        Schema::dropIfExists('birth_records');
    }
};
