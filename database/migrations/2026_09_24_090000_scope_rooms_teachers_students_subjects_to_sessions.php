<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms, teachers, students and subjects stop being a shared, app-wide
 * catalog and become owned by exactly one exam session — so each session
 * (e.g. a different department's) manages its own, and nothing done in
 * one is visible in another. The session_rooms pivot is dropped: a room
 * that belongs to the session is simply one of its rooms.
 *
 * Existing rows have no owner, so they are cleared first (the app owner
 * confirmed there is no data worth keeping). Users and their permissions
 * are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'activity_logs', 'report_files', 'duty_assignments', 'seat_assignments',
            'subject_slot_assignments', 'enrollments', 'time_slots',
            'session_teacher_constraints', 'session_rooms', 'exam_sessions',
            'students', 'subjects', 'teachers', 'rooms',
        ] as $legacyTable) {
            DB::table($legacyTable)->delete();
        }

        Schema::enableForeignKeyConstraints();

        Schema::dropIfExists('session_rooms');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->foreignId('exam_session_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_session_id', 'name']);
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['pernr']);
            $table->foreignId('exam_session_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_session_id', 'email']);
            $table->unique(['exam_session_id', 'pernr']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['roll_no']);
            $table->foreignId('exam_session_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_session_id', 'roll_no']);
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->foreignId('exam_session_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_session_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['subjects', 'students', 'teachers', 'rooms'] as $table) {
            DB::table($table)->delete();
        }

        Schema::enableForeignKeyConstraints();

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_session_id');
            $table->unique('code');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_session_id');
            $table->unique('roll_no');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_session_id');
            $table->unique('email');
            $table->unique('pernr');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_session_id');
            $table->unique('name');
        });

        Schema::create('session_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('capacity_override')->nullable();
            $table->timestamps();

            $table->unique(['exam_session_id', 'room_id']);
        });
    }
};
