<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Pattern the extension uses to decide which pages to monitor, e.g. "https://*.coursera.org/learn/*"
            $table->string('target_url_pattern')->nullable()->after('platform_name');
            $table->decimal('max_playback_speed', 3, 2)->default(1.25)->after('target_url_pattern');
            $table->boolean('allow_muted_playback')->default(false)->after('max_playback_speed');
            $table->unsignedSmallInteger('max_skip_seconds')->default(10)->after('allow_muted_playback');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['target_url_pattern', 'max_playback_speed', 'allow_muted_playback', 'max_skip_seconds']);
        });
    }
};
