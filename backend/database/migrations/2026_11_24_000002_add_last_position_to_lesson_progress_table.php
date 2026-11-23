<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            // Playhead position at the previous heartbeat; used to detect forward timeline skips.
            $table->decimal('last_position', 10, 2)->nullable()->after('watched_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropColumn('last_position');
        });
    }
};
