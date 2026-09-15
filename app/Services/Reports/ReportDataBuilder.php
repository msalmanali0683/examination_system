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
 *
 * Every method takes an optional $date (Y-m-d) to restrict a report to a
 * single exam day — used by the "generate for one date" filter on the
 * Reports tab. Leaving it null reports on the whole session as before.
 */
class ReportDataBuilder
{
    private array $chartsCacheByDate = [];

    /**
     * One entry per room-per-slot in use, each with its seat grid keyed
     * [column][row] (our "column" is the template's "Row # N" block, our
     * "row" is the position printed down inside that block — see
     * RoomFiller::seatOrder()), the distinct subject/section(s) seated
     * there, and the invigilators on duty for that room+slot.
     */
    public function seatingCharts(ExamSession $session, ?string $date = null): Collection
    {
        $cacheKey = $date ?? '_all';

        if (isset($this->chartsCacheByDate[$cacheKey])) {
            return $this->chartsCacheByDate[$cacheKey];
        }

        $duties = $this->dutyNamesByRoomSlot($session, $date);

        $query = SeatAssignment::where('exam_session_id', $session->id)
            ->with(['enrollment.student', 'enrollment.subject', 'room', 'timeSlot']);

        if ($date) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereDate('date', $date));
        }

        return $this->chartsCacheByDate[$cacheKey] = $query->get()
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
    public function datesheetRowsByDate(ExamSession $session, ?string $date = null): Collection
    {
        $rows = $this->seatingCharts($session, $date)->flatMap(function ($chart) {
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
    public function dutyRowsByTeacher(ExamSession $session, ?string $date = null): Collection
    {
        $subjectsByRoomSlot = $this->seatingCharts($session, $date)
            ->mapWithKeys(fn ($chart) => [
                $chart->timeSlot->id.'-'.$chart->room->id => $chart->subjectsSections
                    ->map(fn ($ss) => $ss->subject->code.' ('.$ss->section.')')
                    ->implode(', '),
            ]);

        $query = DutyAssignment::where('exam_session_id', $session->id)
            ->with(['teacher', 'timeSlot', 'room']);

        if ($date) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereDate('date', $date));
        }

        return $query->get()
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

    /**
     * One group per subject with every seated student in it (roll no,
     * name, section, room, seat, invigilator) — an attendance-style sheet
     * organized by subject rather than by room.
     */
    public function subjectWiseSeatingRows(ExamSession $session, ?string $date = null): Collection
    {
        $duties = $this->dutyNamesByRoomSlot($session, $date);

        $query = SeatAssignment::where('exam_session_id', $session->id)
            ->with(['enrollment.student', 'enrollment.subject', 'room', 'timeSlot']);

        if ($date) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereDate('date', $date));
        }

        return $query->get()
            ->map(fn (SeatAssignment $sa) => (object) [
                'subjectId' => $sa->enrollment->subject_id,
                'code' => $sa->enrollment->subject->code,
                'title' => $sa->enrollment->subject->title,
                'section' => $sa->enrollment->section,
                'rollNo' => $sa->enrollment->student->roll_no,
                'studentName' => $sa->enrollment->student->name,
                'date' => $sa->timeSlot->date,
                'day' => $sa->timeSlot->date->format('l'),
                'startTime' => $sa->timeSlot->start_time,
                'room' => $sa->room->name,
                'seat' => "Row {$sa->row_number}, Col {$sa->column_number}",
                'invigilator' => $duties->get("{$sa->time_slot_id}-{$sa->room_id}", collect())->implode(' & '),
            ])
            ->groupBy('subjectId')
            ->map(fn ($rows) => (object) [
                'code' => $rows->first()->code,
                'title' => $rows->first()->title,
                'rows' => $rows->sortBy(fn ($r) => "{$r->section}|{$r->rollNo}")->values(),
            ])
            ->sortBy('code')
            ->values();
    }

    /**
     * One group per section (e.g. "BSAI 2A") with that batch's own exam
     * schedule in date/time order — a personal datesheet for one class.
     */
    public function batchScheduleRows(ExamSession $session, ?string $date = null): Collection
    {
        return $this->seatingCharts($session, $date)
            ->flatMap(fn ($chart) => $chart->subjectsSections->map(fn ($ss) => (object) [
                'section' => $ss->section,
                'code' => $ss->subject->code,
                'title' => $ss->subject->title,
                'date' => $chart->timeSlot->date,
                'day' => $chart->timeSlot->date->format('l'),
                'startTime' => $chart->timeSlot->start_time,
                'endTime' => $chart->timeSlot->end_time,
                'room' => $chart->room->name,
                'invigilator' => $chart->teacherNames->implode(' & '),
            ]))
            ->groupBy('section')
            ->sortKeys()
            ->map(fn ($rows, $section) => (object) [
                'section' => $section,
                'rows' => $rows->sortBy(fn ($r) => $r->date->format('Y-m-d').$r->startTime)->values(),
            ])
            ->values();
    }

    private function dutyNamesByRoomSlot(ExamSession $session, ?string $date = null): Collection
    {
        $query = DutyAssignment::where('exam_session_id', $session->id)->with('teacher');

        if ($date) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereDate('date', $date));
        }

        return $query->get()
            ->groupBy(fn (DutyAssignment $duty) => $duty->time_slot_id.'-'.$duty->room_id)
            ->map(fn (Collection $duties) => $duties->pluck('teacher.name')->filter()->values());
    }
}
