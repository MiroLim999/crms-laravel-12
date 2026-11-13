<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two leftovers of the Staff role (docs/SUPER-ADMIN-ONLY-PLAN.md):
 *
 * - A correction's reason is now optional (D1), so change_requests.reason may be empty.
 * - "Staff may choose the OCR model" is gone (R6): the active model is always used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });

        if (Schema::hasColumn('ocr_settings', 'allow_staff_model_choice')) {
            Schema::table('ocr_settings', function (Blueprint $table): void {
                $table->dropColumn('allow_staff_model_choice');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ocr_settings', function (Blueprint $table): void {
            $table->boolean('allow_staff_model_choice')->default(false);
        });

        // Rows saved without a reason would block NOT NULL, so give them one first.
        DB::table('change_requests')->whereNull('reason')->update(['reason' => '']);

        Schema::table('change_requests', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
        });
    }
};
