<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('schema');
            $table->string('change_note')->nullable();
            $table->enum('source', ['manual', 'ai_generate', 'ai_edit', 'import', 'rollback'])->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};
