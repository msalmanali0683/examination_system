<?php

namespace App\Services\Reports;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Services\Generation\SemesterExtractor;
use Illuminate\Support\Collection;

/**
 * Shapes the raw seat/duty assignment rows into the groupings the real
 * department templates use: one seating chart per room-per-slot (column
 * blocks matching "Row # N" in templates/*\/Sitting Plan*.xlsx), a flat
 * datesheet grouped by date (templates/Midterm Datesheet Spring 2026
 * (Students).xlsx), and a duty roster grouped by teacher.
 *
 * Every method takes an optional $date (Y-m-d) to restrict a report to a
 * single exam day, and an optional $timeSlotIds to narrow further to
 * specific slots within that day — both used by the Reports tab filter.
 * When $timeSlotIds is given it takes precedence over $date entirely
 * (the slots already imply which day they're on). Leaving both null
 * reports on the whole session as before.
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
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function seatingCharts(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $cacheKey = ($date ?? '_all').'|'.($timeSlotIds ? implode(',', $timeSlotIds) : '_allSlots');

        if (isset($this->chartsCacheByDate[$cacheKey])) {
            return $this->chartsCacheByDate[$cacheKey];
        }

        $duties = $this->dutyNamesByRoomSlot($session, $date, $timeSlotIds);

        $query = SeatAssignment::where('exam_session_id', $session->id)
            ->with(['enrollment.student', 'enrollment.subject', 'room', 'timeSlot']);

        $this->applySlotFilter($query, $date, $timeSlotIds);

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
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function datesheetRowsByDate(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $rows = $this->seatingCharts($session, $date, $timeSlotIds)->flatMap(function ($chart) {
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
     * A minimal, student-facing datesheet: just date, day, the subject's
     * full title and its slot, deduplicated so a subject split across
     * several rooms/sections in the same slot still prints once — no room,
     * section or invigilator detail. Grouped by date like
     * datesheetRowsByDate().
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function simpleDatesheetRowsByDate(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $rows = $this->seatingCharts($session, $date, $timeSlotIds)
            ->flatMap(fn ($chart) => $chart->subjectsSections->map(fn ($ss) => (object) [
                'subjectId' => $ss->subject->id,
                'title' => $ss->subject->title,
                'timeSlot' => $chart->timeSlot,
            ]))
            ->unique(fn ($row) => $row->subjectId.'-'.$row->timeSlot->id)
            ->map(fn ($row) => (object) [
                'title' => $row->title,
                'date' => $row->timeSlot->date,
                'day' => $row->timeSlot->date->format('l'),
                'startTime' => $row->timeSlot->start_time,
                'slot' => substr($row->timeSlot->start_time, 0, 5).' - '.substr($row->timeSlot->end_time, 0, 5),
            ]);

        return $rows
            ->sortBy(fn ($row) => $row->date->format('Y-m-d').$row->startTime.$row->title)
            ->groupBy(fn ($row) => $row->date->format('Y-m-d'));
    }

    /**
     * One group per teacher with their duties in date/time order.
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function dutyRowsByTeacher(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $subjectsByRoomSlot = $this->seatingCharts($session, $date, $timeSlotIds)
            ->mapWithKeys(fn ($chart) => [
                $chart->timeSlot->id.'-'.$chart->room->id => $chart->subjectsSections
                    ->map(fn ($ss) => $ss->subject->code.' ('.$ss->section.')')
                    ->implode(', '),
            ]);

        $query = DutyAssignment::where('exam_session_id', $session->id)
            ->with(['teacher', 'timeSlot', 'room']);

        $this->applySlotFilter($query, $date, $timeSlotIds);

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
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function subjectWiseSeatingRows(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $duties = $this->dutyNamesByRoomSlot($session, $date, $timeSlotIds);

        $query = SeatAssignment::where('exam_session_id', $session->id)
            ->with(['enrollment.student', 'enrollment.subject', 'room', 'timeSlot']);

        $this->applySlotFilter($query, $date, $timeSlotIds);

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
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function batchScheduleRows(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        return $this->seatingCharts($session, $date, $timeSlotIds)
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

    /**
     * One row per subject-per-slot, wide-format: up to several
     * {room, count, invigilator} triples on the same row instead of one
     * row per room — matches the department's own "Formatted Datesheet"
     * template (module code/desc, semester, student count, date/day/slot,
     * then Room 1/Room 1 Count/Invigilator Room 1, Room 2/..., etc).
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function formattedDatesheetRows(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $charts = $this->seatingCharts($session, $date, $timeSlotIds);

        $sectionsBySubject = $charts
            ->flatMap(fn ($chart) => $chart->subjectsSections)
            ->groupBy(fn ($ss) => $ss->subject->id)
            ->map(fn ($rows) => $rows->pluck('section'));

        $rows = collect();

        foreach ($charts as $chart) {
            $countBySubject = $chart->subjectsSections
                ->groupBy(fn ($ss) => $ss->subject->id)
                ->map(fn ($group) => (object) [
                    'subject' => $group->first()->subject,
                    'count' => $group->sum('count'),
                ]);

            foreach ($countBySubject as $subjectId => $info) {
                $key = $subjectId.'|'.$chart->timeSlot->id;
                $entry = $rows->get($key) ?? (object) [
                    'subject' => $info->subject,
                    'timeSlot' => $chart->timeSlot,
                    'studentCount' => 0,
                    'rooms' => collect(),
                ];

                $entry->studentCount += $info->count;
                $entry->rooms->push((object) [
                    'room' => $chart->room->name,
                    'count' => $info->count,
                    'invigilator' => $chart->teacherNames->implode(' & '),
                ]);

                $rows->put($key, $entry);
            }
        }

        return $rows->values()
            ->map(function ($entry) use ($sectionsBySubject) {
                $entry->rooms = $entry->rooms->sortByDesc('count')->values();
                $entry->semester = SemesterExtractor::label($sectionsBySubject->get($entry->subject->id, collect()));

                return $entry;
            })
            ->sortBy(fn ($e) => $e->timeSlot->date->format('Y-m-d').$e->timeSlot->start_time.$e->subject->code)
            ->values();
    }

    /**
     * One row per duty assignment (teacher, time, room), grouped by date
     * — a print/sign-in sheet matching the department's own "Attendance
     * Sheet" template (one table per day, Teacher Name / Time / Room # /
     * a blank Signature column). A teacher with more than one duty the
     * same day appears once per duty, same as the real sign-in sheets.
     *
     * @param  int[]|null  $timeSlotIds
     */
    public function teacherAttendanceRows(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $query = DutyAssignment::where('exam_session_id', $session->id)->with(['teacher', 'timeSlot', 'room']);

        $this->applySlotFilter($query, $date, $timeSlotIds);

        return $query->get()
            ->map(fn (DutyAssignment $duty) => (object) [
                'teacherName' => $duty->teacher->name,
                'date' => $duty->timeSlot->date,
                'startTime' => $duty->timeSlot->start_time,
                'endTime' => $duty->timeSlot->end_time,
                'room' => $duty->room->name,
            ])
            ->sortBy(fn ($row) => $row->date->format('Y-m-d').$row->startTime.$row->room)
            ->groupBy(fn ($row) => $row->date->format('Y-m-d'));
    }

    /**
     * @param  int[]|null  $timeSlotIds
     */
    private function dutyNamesByRoomSlot(ExamSession $session, ?string $date = null, ?array $timeSlotIds = null): Collection
    {
        $query = DutyAssignment::where('exam_session_id', $session->id)->with('teacher');

        $this->applySlotFilter($query, $date, $timeSlotIds);

        return $query->get()
            ->groupBy(fn (DutyAssignment $duty) => $duty->time_slot_id.'-'.$duty->room_id)
            ->map(fn (Collection $duties) => $duties->pluck('teacher.name')->filter()->values());
    }

    /**
     * Restricts a query to specific time slots when given, otherwise
     * falls back to a whole-day filter — the one place both filters are
     * interpreted, so every report method applies them identically.
     *
     * @param  int[]|null  $timeSlotIds
     */
    private function applySlotFilter($query, ?string $date, ?array $timeSlotIds): void
    {
        if (! empty($timeSlotIds)) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereIn('time_slots.id', $timeSlotIds));
        } elseif ($date) {
            $query->whereHas('timeSlot', fn ($q) => $q->whereDate('date', $date));
        }
    }
}
