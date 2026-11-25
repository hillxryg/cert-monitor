<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            // The module a tracked item belongs to. A narrated SCORM module reports one item
            // (lesson_key) per slide, all sharing one group. Null = the item is its own module.
            $table->string('lesson_group')->nullable()->after('lesson_key');
            $table->index(['enrollment_id', 'lesson_group']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex(['enrollment_id', 'lesson_group']);
            $table->dropColumn('lesson_group');
        });
    }
};
