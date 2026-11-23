<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('platform_name')->nullable();          // e.g. "Coursera", "Udemy"
            $table->string('join_code', 12)->unique();
            $table->unsignedTinyInteger('required_completion_percent')->default(90);
            $table->unsignedTinyInteger('min_integrity_score')->default(70);
            $table->string('status', 20)->default('active')->index(); // draft | active | closed
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
