<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('session_teacher_constraints', function (Blueprint $table) {
            // ISO weekday numbers (1=Monday .. 6=Saturday) the teacher is
            // NOT available for duty this session. Null/empty = available
            // every day.
            $table->json('unavailable_days')->nullable()->after('is_excluded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_teacher_constraints', function (Blueprint $table) {
            $table->dropColumn('unavailable_days');
        });
    }
};
