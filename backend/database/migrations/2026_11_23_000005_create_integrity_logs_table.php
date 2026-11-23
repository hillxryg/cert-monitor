<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_progress_id')->nullable()->constrained('lesson_progress')->nullOnDelete();
            $table->string('event_type', 40);                       // see App\Enums\IntegrityEventType
            $table->unsignedTinyInteger('points_deducted')->default(0);
            $table->json('meta')->nullable();                       // e.g. {"from":120,"to":480} or {"rate":2}
            $table->timestamp('occurred_at');                       // when it happened client-side (validated server-side)
            $table->timestamp('created_at')->useCurrent();          // append-only: no updated_at

            $table->index(['enrollment_id', 'occurred_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_logs');
    }
};
