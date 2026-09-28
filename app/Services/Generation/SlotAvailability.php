<?php

namespace App\Services\Generation;

use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Which rooms and teachers are switched off for which specific time slot
 * (see the slot-availability page) — the one place that reads those
 * tables, so seating, duty allocation, the capacity check and the manual
 * boards all interpret them the same way. Whole-weekday unavailability on
 * teachers is a separate, older rule (SessionTeacherConstraint) and is
 * not repeated here.
 */
class SlotAvailability
{
    /**
     * @return array<int, int[]> time_slot_id => ids of rooms that can't be used in that slot
     */
    public static function roomsOffBySlot(ExamSession $session): array
    {
        return self::offBySlot('room_unavailable_slots', 'room_id', $session);
    }

    /**
     * @return array<int, int[]> time_slot_id => ids of teachers who can't do duty in that slot
     */
    public static function teachersOffBySlot(ExamSession $session): array
    {
        return self::offBySlot('teacher_unavailable_slots', 'teacher_id', $session);
    }

    /**
     * @return array<int, int[]>
     */
    private static function offBySlot(string $table, string $column, ExamSession $session): array
    {
        return DB::table($table)
            ->join('time_slots', 'time_slots.id', '=', "{$table}.time_slot_id")
            ->where('time_slots.exam_session_id', $session->id)
            ->get(["{$table}.time_slot_id as slot_id", "{$table}.{$column} as item_id"])
            ->groupBy('slot_id')
            ->map(fn ($rows) => $rows->pluck('item_id')->map(fn ($id) => (int) $id)->all())
            ->mapWithKeys(fn ($ids, $slotId) => [(int) $slotId => $ids])
            ->all();
    }
}
