<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review & Export (step 4) saves each row of a document into its register
 * table (birth_records, death_records, marriage_records):
 *
 * - civil_record_id: the saved scan record a row came from (saving it again
 *   replaces its rows);
 * - only the register number, book, page and names are required; any other
 *   column a register leaves blank stays blank (null);
 * - dates are kept as written on the register ("Jan, 07,1978"), as
 *   date_of_birth already was, so nothing read is lost to a date parser;
 * - document_templates.field_map: which layout column fills which field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birth_records', function (Blueprint $table) {
            $table->foreignId('civil_record_id')->nullable()->after('id')->constrained('records')->nullOnDelete();
            $table->string('date_of_birth', 64)->nullable()->change();
            $table->string('sex', 16)->nullable()->change();
            $table->string('date_of_reg', 64)->nullable()->change();
            $table->string('place_of_birth', 255)->nullable()->change();
            $table->string('name_of_mother', 255)->nullable()->change();
            $table->string('name_of_father', 255)->nullable()->change();
            $table->string('parents_marriage_date', 64)->nullable()->change();
        });

        Schema::table('death_records', function (Blueprint $table) {
            $table->foreignId('civil_record_id')->nullable()->after('id')->constrained('records')->nullOnDelete();
            $table->string('date_of_death', 64)->nullable()->change();
            $table->string('sex', 16)->nullable()->change();
            $table->string('date_of_reg', 64)->nullable()->change();
            $table->string('place_of_death', 255)->nullable()->change();
            $table->string('date_of_birth', 64)->nullable()->change();
        });

        Schema::table('marriage_records', function (Blueprint $table) {
            $table->foreignId('civil_record_id')->nullable()->after('id')->constrained('records')->nullOnDelete();
            $table->string('date_of_marriage', 64)->nullable()->change();
            $table->string('date_of_reg', 64)->nullable()->change();
            $table->string('place_of_marriage', 255)->nullable()->change();
        });

        Schema::table('document_templates', function (Blueprint $table) {
            // { "lower-cased column name": "field_key" }
            $table->json('field_map')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('field_map');
        });
        foreach (['birth_records', 'death_records', 'marriage_records'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('civil_record_id');
            });
        }
        // The columns stay nullable text: turning them back into required dates
        // could fail on (or lose) values saved as written.
    }
};
