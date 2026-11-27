<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A register number may repeat on a page: the books themselves have repeats.
 * The unique (book_no, page_no, reg_no) key is replaced by a plain index on
 * book and page, as before 2026_11_25_000400. A book and page is still saved
 * by one record only (checked when it is saved).
 */
return new class extends Migration
{
    private const TABLES = ['birth_records', 'death_records', 'marriage_records'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['book_no', 'page_no'], "{$name}_book_no_page_no_index");
                $table->dropUnique("{$name}_entry_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $duplicates = DB::table($name)
                ->select('book_no', 'page_no', 'reg_no')
                ->groupBy('book_no', 'page_no', 'reg_no')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($duplicates > 0) {
                throw new RuntimeException("{$name} has {$duplicates} repeated register numbers on a page. Remove the repeats, then roll back again.");
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unique(['book_no', 'page_no', 'reg_no'], "{$name}_entry_unique");
                $table->dropIndex("{$name}_book_no_page_no_index");
            });
        }
    }
};
