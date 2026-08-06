<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')
            ->where('icon', 'bx-file-blank')
            ->update(['icon' => 'bx-file']);
    }

    public function down(): void
    {
        DB::table('document_types')
            ->where('is_system', false)
            ->where('icon', 'bx-file')
            ->update(['icon' => 'bx-file-blank']);
    }
};
