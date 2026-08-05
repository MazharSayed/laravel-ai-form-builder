<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->unsignedInteger('form_schema_version');
            $table->json('data');
            $table->json('meta')->nullable();
            $table->enum('status', ['completed', 'flagged_spam'])->default('completed');
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index(['form_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
