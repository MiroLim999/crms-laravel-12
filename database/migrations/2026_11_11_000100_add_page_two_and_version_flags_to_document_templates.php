<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two flags on layouts:
 *
 * - is_page_two: the layout is page 2 of one main layout (a two-page spread)
 *   and belongs to it alone. It is not listed, published or edited by itself.
 * - is_version: the layout was saved as a new version of its parent (not a
 *   duplicate), so publishing it replaces the parent for Staff.
 *
 * Page 2 layouts used to be shared: a new version or a duplicate pointed at
 * the same page 2 as the layout it came from, so editing one changed both.
 * Each main layout now gets its own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->boolean('is_page_two')->default(false)->after('page_two_template_id');
            $table->boolean('is_version')->default(false)->after('parent_id');
            // When it was last published: Staff's layout list puts the newest first.
            $table->timestamp('published_at')->nullable()->after('is_active');
        });

        DB::table('document_templates')->where('is_active', true)->update(['published_at' => DB::raw('updated_at')]);

        // Versions are known from the audit log ("template.versioned").
        $versions = DB::table('audit_logs')
            ->where('action', 'template.versioned')
            ->where('auditable_type', 'App\\Models\\DocumentTemplate')
            ->pluck('auditable_id')
            ->filter()
            ->unique()
            ->all();
        if ($versions !== []) {
            DB::table('document_templates')
                ->whereIn('id', $versions)
                ->whereNotNull('parent_id')
                ->update(['is_version' => true]);
        }

        $owners = DB::table('document_templates')
            ->whereNotNull('page_two_template_id')
            ->orderBy('id')
            ->get(['id', 'page_two_template_id']);

        $claimed = [];
        foreach ($owners as $owner) {
            $pageTwoId = (int) $owner->page_two_template_id;
            $pageTwo = DB::table('document_templates')->where('id', $pageTwoId)->first();
            if ($pageTwo === null) {
                continue;
            }

            // The first main layout keeps its page 2 when nothing else uses that
            // layout as a layout of its own; every other one gets a copy.
            $standalone = (bool) $pageTwo->is_active
                || $pageTwo->page_two_template_id !== null
                || DB::table('records')->where('document_template_id', $pageTwoId)->exists();

            if (! $standalone && ! isset($claimed[$pageTwoId])) {
                $claimed[$pageTwoId] = true;
                DB::table('document_templates')->where('id', $pageTwoId)->update(['is_page_two' => true]);

                continue;
            }

            $copyId = $this->copyLayout($pageTwo);
            DB::table('document_templates')->where('id', $owner->id)->update(['page_two_template_id' => $copyId]);
        }
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['is_page_two', 'is_version', 'published_at']);
        });
    }

    /** A page 2 copy of a layout row with its fields; returns the new id. */
    private function copyLayout(object $layout): int
    {
        $row = (array) $layout;
        unset($row['id']);
        $now = now();

        $id = DB::table('document_templates')->insertGetId([
            ...$row,
            'is_active' => false,
            'published_at' => null,
            'is_page_two' => true,
            'is_version' => false,
            'parent_id' => null,
            'page_two_template_id' => null,
            'revision' => 1,
            'sample_path' => null,
            'sample_original_name' => null,
            'sample_mime' => null,
            'sample_size' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (DB::table('document_template_fields')->where('document_template_id', $layout->id)->get() as $field) {
            $fieldRow = (array) $field;
            unset($fieldRow['id']);
            DB::table('document_template_fields')->insert([
                ...$fieldRow,
                'document_template_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $id;
    }
};
