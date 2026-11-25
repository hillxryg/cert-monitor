<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // How many modules the certification has (e.g. 12). Null = unknown: overall
            // completion then averages only the modules the student has started.
            $table->unsignedSmallInteger('expected_lessons')->nullable()->after('min_integrity_score');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('expected_lessons');
        });
    }
};
