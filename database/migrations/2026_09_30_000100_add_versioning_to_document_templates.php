<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // The layout this one was copied from: a new version of a layout
            // already in use, or a duplicate. A layout in use is never changed
            // in place, so every record keeps the layout it was read with.
            $table->foreignId('parent_id')->nullable()->after('document_type_id')
                ->constrained('document_templates')->nullOnDelete();

            // Goes up on every save. The builder sends back the revision it
            // opened, so an admin working on an older copy cannot overwrite a
            // newer save without seeing it first.
            $table->unsignedInteger('revision')->default(1)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('revision');
        });
    }
};
