<?php

namespace App\Services\Reports;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use Illuminate\Support\Collection;

/**
 * Shapes the raw seat/duty assignment rows into the groupings the real
 * department templates use: one seating chart per room-per-slot (column
 * blocks matching "Row # N" in templates/*\/Sitting Plan*.xlsx), a flat
 * datesheet grouped by date (templates/Midterm Datesheet Spring 2026
 * (Students).xlsx), and a duty roster grouped by teacher.
 */
class ReportDataBuilder
{
    private ?Collection $chartsCache = null;

    /**
     * One entry per room-per-slot in use, each with its seat grid keyed
     * [column][row] (our "column" is the template's "Row # N" block, our
     * "row" is the position printed down inside that block — see
     * RoomFiller::seatOrder()), the distinct subject/section(s) seated
     * there, and the invigilators on duty for that room+slot.
     */
    public function seatingCharts(ExamSession $session): Collection
    {
        if ($this->chartsCache !== null) {
            return $this->chartsCache;
        }

        $duties = $this->dutyNamesByRoomSlot($session);

        return $this->chartsCache = SeatAssignment::where('exam_session_id', $session->id)
            ->with(['enrollment.student', 'enrollment.subject', 'room', 'timeSlot'])
            ->get()
            ->groupBy(fn (SeatAssignment $sa) => $sa->time_slot_id.'-'.$sa->room_id)
            ->map(function (Collection $group) use ($duties) {
                $first = $group->first();
                $room = $first->room;
                $slot = $first->timeSlot;

                $subjectsSections = $group
                    ->groupBy(fn (SeatAssignment $sa) => $sa->enrollment->subject_id.'|'.$sa->enrollment->section)
                    ->map(function (Collection $rows) {
                        $enrollment = $rows->first()->enrollment;

                        return (object) [
                            'subject' => $enrollment->subject,
                            'section' => $enrollment->section,
                            'count' => $rows->count(),
                        ];
                    })
                    ->values();

                $grid = [];
                foreach ($group as $sa) {
                    $grid[$sa->column_number][$sa->row_number] = $sa;
                }
                ksort($grid);
                foreach ($grid as &$column) {
                    ksort($column);
                }
                unset($column);

                return (object) [
                    'room' => $room,
                    'timeSlot' => $slot,
                    'subjectsSections' => $subjectsSections,
                    'teacherNames' => $duties->get($slot->id.'-'.$room->id, collect()),
                    'grid' => $grid,
                    'studentCount' => $group->count(),
                ];
            })
            ->sortBy(fn ($chart) => $chart->timeSlot->date->format('Y-m-d').$chart->timeSlot->start_time.$chart->room->name)
            ->values();
    }

    /**
     * Flat rows matching templates/Midterm Datesheet Spring 2026
     * (Students).xlsx: one row per subject+section+room, grouped by date.
     */
    public function datesheetRowsByDate(ExamSession $session): Collection
    {
        $rows = $this->seatingCharts($session)->flatMap(function ($chart) {
            return $chart->subjectsSections->map(fn ($ss) => (object) [
                'code' => $ss->subject->code,
                'title' => $ss->subject->title,
                'section' => $ss->section,
                'date' => $chart->timeSlot->date,
                'day' => $chart->timeSlot->date->format('l'),
                'startTime' => $chart->timeSlot->start_time,
                'endTime' => $chart->timeSlot->end_time,
                'room' => $chart->room->name,
                'invigilator' => $chart->teacherNames->implode(' & '),
                'studentCount' => $ss->count,
            ]);
        });

        return $rows
            ->sortBy(fn ($row) => $row->date->format('Y-m-d').$row->startTime.$row->room)
            ->groupBy(fn ($row) => $row->date->format('Y-m-d'));
    }

    /**
     * One group per teacher with their duties in date/time order.
     */
    public function dutyRowsByTeacher(ExamSession $session): Collection
    {
        $subjectsByRoomSlot = $this->seatingCharts($session)
            ->mapWithKeys(fn ($chart) => [
                $chart->timeSlot->id.'-'.$chart->room->id => $chart->subjectsSections
                    ->map(fn ($ss) => $ss->subject->code.' ('.$ss->section.')')
                    ->implode(', '),
            ]);

        return DutyAssignment::where('exam_session_id', $session->id)
            ->with(['teacher', 'timeSlot', 'room'])
            ->get()
            ->sortBy(fn (DutyAssignment $duty) => $duty->timeSlot->date->format('Y-m-d').$duty->timeSlot->start_time)
            ->groupBy(fn (DutyAssignment $duty) => $duty->teacher_id)
            ->map(function (Collection $duties) use ($subjectsByRoomSlot) {
                return (object) [
                    'teacher' => $duties->first()->teacher,
                    'duties' => $duties->map(fn (DutyAssignment $duty) => (object) [
                        'date' => $duty->timeSlot->date,
                        'day' => $duty->timeSlot->date->format('l'),
                        'startTime' => $duty->timeSlot->start_time,
                        'endTime' => $duty->timeSlot->end_time,
                        'room' => $duty->room->name,
                        'subjects' => $subjectsByRoomSlot->get($duty->time_slot_id.'-'.$duty->room_id, ''),
                    ])->values(),
                ];
            })
            ->sortBy(fn ($group) => $group->teacher->name)
            ->values();
    }

    private function dutyNamesByRoomSlot(ExamSession $session): Collection
    {
        return DutyAssignment::where('exam_session_id', $session->id)
            ->with('teacher')
            ->get()
            ->groupBy(fn (DutyAssignment $duty) => $duty->time_slot_id.'-'.$duty->room_id)
            ->map(fn (Collection $duties) => $duties->pluck('teacher.name')->filter()->values());
    }
}
