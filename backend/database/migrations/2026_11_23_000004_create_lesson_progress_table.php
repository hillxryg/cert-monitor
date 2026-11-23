<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();

            // Identifies the lesson on the third-party platform (stable id or normalized URL hash).
            $table->string('lesson_key');
            $table->string('lesson_title')->nullable();
            $table->text('lesson_url')->nullable();
            $table->string('content_type', 10)->default('video'); // video | reading

            // Video: merged by App\Services\SegmentMerger -> [[start, end], ...] in seconds.
            $table->json('watched_ranges')->nullable();
            $table->decimal('video_duration', 10, 2)->nullable();
            $table->decimal('watched_seconds', 10, 2)->default(0);

            // Reading / focus dwell time.
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedTinyInteger('max_scroll_percent')->default(0);

            $table->decimal('completion_percent', 5, 2)->default(0); // server-computed only
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
