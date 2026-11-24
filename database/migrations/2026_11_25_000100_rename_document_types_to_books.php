<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three registers are named after their books: "Book of Birth",
 * "Book of Death", "Book of Marriage" (were "… Certificate"). A name an
 * administrator had already changed is left as it is.
 */
return new class extends Migration
{
    private const NAMES = [
        'birth' => ['Birth Certificate', 'Book of Birth'],
        'death' => ['Death Certificate', 'Book of Death'],
        'marriage' => ['Marriage Certificate', 'Book of Marriage'],
    ];

    public function up(): void
    {
        foreach (self::NAMES as $key => [$old, $new]) {
            DB::table('document_types')->where('key', $key)->where('name', $old)->update(['name' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $key => [$old, $new]) {
            DB::table('document_types')->where('key', $key)->where('name', $new)->update(['name' => $old, 'updated_at' => now()]);
        }
    }
};
