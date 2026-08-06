<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Starter templates, one active per certificate type.
 *
 * The field boxes come from DocumentType::defaultFields(), ported from the
 * prototype's web/js/config.js. Without these, Staff cannot scan anything until a
 * Super Admin builds a template by hand - so seeding them makes a fresh install
 * immediately usable, and they remain fully editable in the builder.
 */
class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::where('email', config('crms.super_admin.email'))->first();

        foreach (DocumentType::cases() as $type) {
            $template = DocumentTemplate::firstOrCreate(
                ['doc_type' => $type->value, 'name' => $type->label()],
                [
                    'description' => 'Starter layout ported from the TrOCR prototype.',
                    'paper_size' => PaperSize::Letter->value,
                    'orientation' => PageOrientation::Portrait->value,
                    'is_active' => true,
                    'created_by' => $superAdmin?->getKey(),
                ],
            );

            if ($template->fields()->exists()) {
                continue;
            }

            foreach ($type->defaultFields() as $index => $field) {
                $template->fields()->create([
                    ...$field,
                    'sort_order' => $index,
                    'is_required' => true,
                ]);
            }

            $this->command?->info("Template ready: {$type->label()} (".count($type->defaultFields()).' fields)');
        }
    }
}
