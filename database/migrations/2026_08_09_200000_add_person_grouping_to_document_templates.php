<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('grouping_mode', 16)->default('auto')->after('description');
        });

        Schema::table('document_template_fields', function (Blueprint $table): void {
            $table->unsignedSmallInteger('person_group')->nullable()->after('name');
            $table->unsignedSmallInteger('person_field_order')->nullable()->after('person_group');
            $table->index(
                ['document_template_id', 'person_group', 'person_field_order'],
                'template_fields_person_group_order_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table): void {
            $table->dropIndex('template_fields_person_group_order_index');
            $table->dropColumn(['person_group', 'person_field_order']);
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('grouping_mode');
        });
    }
};
