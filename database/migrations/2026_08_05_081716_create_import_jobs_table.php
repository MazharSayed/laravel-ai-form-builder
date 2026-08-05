<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('original_filename');
            $table->string('disk_path');
            $table->enum('type', ['docx', 'xlsx']);
            $table->enum('status', ['pending', 'processing', 'ready_for_review', 'committed', 'failed'])->default('pending');
            $table->json('detected_schema')->nullable();
            $table->json('mapping_overrides')->nullable();
            $table->json('unparseable_blocks')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
