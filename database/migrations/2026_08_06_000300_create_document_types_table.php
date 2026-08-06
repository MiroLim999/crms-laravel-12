<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120)->unique();
            $table->string('short_name', 80);
            $table->string('icon', 50)->default('bx-file-blank');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('document_types')->insert([
            ['key' => 'birth', 'name' => 'Birth Certificate', 'short_name' => 'Birth', 'icon' => 'bx-cake', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'death', 'name' => 'Death Certificate', 'short_name' => 'Death', 'icon' => 'bx-file', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'marriage', 'name' => 'Marriage Certificate', 'short_name' => 'Marriage', 'icon' => 'bx-heart', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->after('doc_type')
                ->constrained('document_types')->restrictOnDelete();
            $table->index(['document_type_id', 'is_active']);
        });

        Schema::table('records', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->after('doc_type')
                ->constrained('document_types')->restrictOnDelete();
            $table->index(['document_type_id', 'status']);
        });

        foreach (DB::table('document_types')->get(['id', 'key']) as $type) {
            DB::table('document_templates')->where('doc_type', $type->key)
                ->update(['document_type_id' => $type->id]);
            DB::table('records')->where('doc_type', $type->key)
                ->update(['document_type_id' => $type->id]);
        }
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
        });
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
        });
        Schema::dropIfExists('document_types');
    }
};
