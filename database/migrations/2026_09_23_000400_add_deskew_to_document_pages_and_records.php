<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            // Degrees the Detect step rotated the page (counter-clockwise) to
            // level its rules. Outlines and crops are in the straightened page.
            $table->decimal('deskew_degrees', 6, 3)->nullable()->after('height');
        });

        Schema::table('records', function (Blueprint $table) {
            // The same rotation, kept with the record: field outlines are
            // fractions of the straightened page, while scan_path holds the
            // file as it was uploaded.
            $table->decimal('scan_rotation', 6, 3)->nullable()->after('scan_mime');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn('scan_rotation');
        });

        Schema::table('document_pages', function (Blueprint $table) {
            $table->dropColumn('deskew_degrees');
        });
    }
};
