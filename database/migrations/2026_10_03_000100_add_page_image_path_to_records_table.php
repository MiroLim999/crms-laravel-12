<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // The page as it was outlined, kept with the record. Detect may
            // straighten (and so resize) a tilted page, and every field outline
            // is a fraction of that page, not of the upload in scan_path: the
            // record page draws its boxes over this image to line up exactly.
            // Null for records submitted before this, which show the upload.
            $table->string('page_image_path')->nullable()->after('scan_rotation');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn('page_image_path');
        });
    }
};
