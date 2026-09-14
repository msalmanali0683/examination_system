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
        Schema::table('subject_slot_assignments', function (Blueprint $table) {
            $table->boolean('duty_matches_sections')->default(false)->after('is_pinned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subject_slot_assignments', function (Blueprint $table) {
            $table->dropColumn('duty_matches_sections');
        });
    }
};
