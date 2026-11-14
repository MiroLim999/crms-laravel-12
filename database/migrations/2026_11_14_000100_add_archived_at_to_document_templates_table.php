<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A layout that saved records were read with cannot be erased: they keep it.
     * "Deleting" it hides it instead (archived_at), as document types are.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
