<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A register entry is identified by its book, page and register number: the
 * app already saves rows with updateOrCreate() on those three columns and
 * refuses a book and page that is saved. This makes the database enforce the
 * same rule, so no other code path can store an entry twice.
 *
 * The plain (book_no, page_no) index is covered by the new one (its leading
 * columns), so it is replaced rather than kept beside it.
 */
return new class extends Migration
{
    private const TABLES = ['birth_records', 'death_records', 'marriage_records'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $duplicates = DB::table($name)
                ->select('book_no', 'page_no', 'reg_no')
                ->groupBy('book_no', 'page_no', 'reg_no')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($duplicates > 0) {
                throw new RuntimeException("{$name} has {$duplicates} register entries saved more than once (same book, page and register number). Remove the repeats, then migrate again.");
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unique(['book_no', 'page_no', 'reg_no'], "{$name}_entry_unique");
                $table->dropIndex("{$name}_book_no_page_no_index");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['book_no', 'page_no']);
                $table->dropUnique("{$name}_entry_unique");
            });
        }
    }
};
