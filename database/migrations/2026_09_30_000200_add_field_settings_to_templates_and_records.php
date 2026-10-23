<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table) {
            // What the field holds for its person: 'name' (shown as the
            // person's title in Verify and the archive) or 'entry' (the
            // register's entry number). Ledger columns keep the same settings
            // inside document_templates.columns.
            $table->string('role', 16)->nullable()->after('person_field_order');
            // How Staff check the value: text, date, number or choice.
            $table->string('value_type', 16)->default('text')->after('role');
            $table->json('options')->nullable()->after('value_type');
            // A short note shown to Staff beside the value in Verify.
            $table->string('hint', 200)->nullable()->after('options');
        });

        Schema::table('record_fields', function (Blueprint $table) {
            // The role the field had in its template when it was submitted, so
            // a record keeps its person's name even if the layout changes.
            $table->string('role', 16)->nullable()->after('person_field_order');
        });

        // The workspace used to know one layout by heart: an 11-column birth
        // register whose first column is the entry number and third the
        // child's name. Those templates now say so themselves.
        $birth = DB::table('document_types')->where('key', 'birth')->value('id');
        DB::table('document_templates')
            ->where('document_type_id', $birth)
            ->whereNotNull('columns')
            ->get(['id', 'columns'])
            ->each(function (object $template) {
                $columns = json_decode((string) $template->columns, true);
                if (! is_array($columns) || count($columns) !== 11
                    || collect($columns)->contains(fn ($column) => isset($column['role']))) {
                    return;
                }
                $columns[0]['role'] = 'entry';
                $columns[2]['role'] = 'name';
                DB::table('document_templates')->where('id', $template->id)
                    ->update(['columns' => json_encode($columns, JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('document_template_fields', function (Blueprint $table) {
            $table->dropColumn(['role', 'value_type', 'options', 'hint']);
        });

        DB::table('document_templates')->whereNotNull('columns')->get(['id', 'columns'])
            ->each(function (object $template) {
                $columns = json_decode((string) $template->columns, true);
                if (! is_array($columns)) {
                    return;
                }
                $columns = array_map(function ($column) {
                    unset($column['role'], $column['required'], $column['type'], $column['options'], $column['hint']);

                    return $column;
                }, $columns);
                DB::table('document_templates')->where('id', $template->id)
                    ->update(['columns' => json_encode($columns, JSON_UNESCAPED_UNICODE)]);
            });
    }
};
