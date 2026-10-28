<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A layout's paper size and orientation were only labels: markers are page
 * fractions and land on every scan's own shape. What is still needed is the
 * page's width : height, to check that tilted markers stay on the page and to
 * tell Staff when a scan looks sideways. It is taken from each layout's
 * sample, or, without an image sample, from the paper it said it was on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->decimal('page_ratio', 7, 5)->nullable()->after('doc_type');
        });

        foreach (DB::table('document_templates')->lazyById() as $template) {
            DB::table('document_templates')->where('id', $template->id)->update([
                'page_ratio' => round($this->sampleRatio($template) ?? $this->paperRatio($template), 5),
            ]);
        }

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['paper_size', 'orientation', 'custom_width_mm', 'custom_height_mm']);
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('paper_size', 32)->default('letter')->after('doc_type');
            $table->string('orientation', 16)->default('portrait')->after('paper_size');
            $table->decimal('custom_width_mm', 8, 2)->nullable()->after('orientation');
            $table->decimal('custom_height_mm', 8, 2)->nullable()->after('custom_width_mm');
        });

        DB::table('document_templates')->where('page_ratio', '>', 1)->update(['orientation' => 'landscape']);

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('page_ratio');
        });
    }

    /** The stored sample's width : height, when it is an image that can be read. */
    private function sampleRatio(object $template): ?float
    {
        if (! $template->sample_path) {
            return null;
        }

        try {
            $path = Storage::disk('local')->path($template->sample_path);
        } catch (Throwable) {
            return null;
        }

        $size = is_file($path) ? @getimagesize($path) : false;

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? $size[0] / $size[1] : null;
    }

    /** The width : height of the paper the layout said it was on. */
    private function paperRatio(object $template): float
    {
        // Portrait width and height in millimetres; custom sizes were entered upright.
        [$width, $height] = match ($template->paper_size) {
            'long_bond' => [215.9, 330.2],
            'a4' => [210.0, 297.0],
            'custom' => [(float) ($template->custom_width_mm ?: 210), (float) ($template->custom_height_mm ?: 297)],
            default => [215.9, 279.4],
        };

        return $template->orientation === 'portrait' ? $width / $height : $height / $width;
    }
};
