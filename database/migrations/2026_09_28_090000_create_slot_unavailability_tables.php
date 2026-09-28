<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slot-level availability: a room or a teacher can be marked unavailable
 * for specific time slots (e.g. free on Monday except its 2nd slot),
 * finer than the existing whole-weekday rule on teachers. One row per
 * (room|teacher, slot) they can NOT be used in; deleting the room,
 * teacher or slot removes its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_unavailable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_slot_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'time_slot_id']);
        });

        Schema::create('teacher_unavailable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_slot_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'time_slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_unavailable_slots');
        Schema::dropIfExists('room_unavailable_slots');
    }
};
