<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->enum('action', ['generate', 'edit', 'import_infer'])->default('generate');
            $table->text('prompt');
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->enum('status', ['queued', 'succeeded', 'failed', 'retried'])->default('queued');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};
