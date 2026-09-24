<?php

namespace App\Services;

use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies rooms and teachers from one session into another. Every copy is a
 * brand-new row owned by the target session — nothing is shared, so editing
 * the copy never touches the original. Used both when duplicating a whole
 * session and when pulling selected rooms/teachers into an existing one.
 *
 * Anything the target already has is skipped rather than duplicated (same
 * room name; same PERNR/email — or, for a teacher with neither, same name).
 */
class SessionDataCopier
{
    private const ROOM_FIELDS = ['name', 'rows', 'columns', 'capacity', 'room_type', 'is_active'];

    private const TEACHER_FIELDS = ['name', 'designation', 'department', 'email', 'phone', 'pernr', 'is_active'];

    /**
     * IDs of $from's rooms whose name the target session already has.
     *
     * @return Collection<int, int>
     */
    public function roomIdsAlreadyIn(ExamSession $from, ExamSession $to): Collection
    {
        $existing = $to->rooms()->pluck('name')->map(fn ($name) => $this->key($name))->flip();

        return $from->rooms()->get(['id', 'name'])
            ->filter(fn ($room) => $existing->has($this->key($room->name)))
            ->pluck('id');
    }

    /**
     * IDs of $from's teachers the target session already has. A PERNR or
     * email match is the same person; with neither to go on, the name is
     * the only way to tell, so an identical name counts.
     *
     * @return Collection<int, int>
     */
    public function teacherIdsAlreadyIn(ExamSession $from, ExamSession $to): Collection
    {
        $existing = $to->teachers()->get(['name', 'email', 'pernr']);
        $pernrs = $existing->pluck('pernr')->filter()->map(fn ($pernr) => $this->key($pernr))->flip();
        $emails = $existing->pluck('email')->filter()->map(fn ($email) => $this->key($email))->flip();
        $names = $existing->pluck('name')->map(fn ($name) => $this->key($name))->flip();

        return $from->teachers()->get(['id', 'name', 'email', 'pernr'])
            ->filter(function ($teacher) use ($pernrs, $emails, $names) {
                if ($teacher->pernr && $pernrs->has($this->key($teacher->pernr))) {
                    return true;
                }

                if ($teacher->email && $emails->has($this->key($teacher->email))) {
                    return true;
                }

                return ! $teacher->pernr && ! $teacher->email && $names->has($this->key($teacher->name));
            })
            ->pluck('id');
    }

    /**
     * @param  int[]|null  $roomIds  only these of $from's rooms; null copies all of them
     * @return array{copied: int, skipped: int}
     */
    public function copyRooms(ExamSession $from, ExamSession $to, ?array $roomIds = null): array
    {
        $rooms = $from->rooms()
            ->when($roomIds !== null, fn ($query) => $query->whereIn('id', $roomIds))
            ->orderBy('name')
            ->get();
        $alreadyThere = $this->roomIdsAlreadyIn($from, $to)->flip();
        $copied = 0;

        DB::transaction(function () use ($rooms, $alreadyThere, $to, &$copied) {
            foreach ($rooms as $room) {
                if ($alreadyThere->has($room->id)) {
                    continue;
                }

                $to->rooms()->create($room->only(self::ROOM_FIELDS));
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => $rooms->count() - $copied];
    }

    /**
     * @param  int[]|null  $teacherIds  only these of $from's teachers; null copies all of them
     * @param  bool  $withConstraints  also copy each teacher's duty limits, day availability and exclusion
     * @return array{copied: int, skipped: int}
     */
    public function copyTeachers(ExamSession $from, ExamSession $to, ?array $teacherIds = null, bool $withConstraints = true): array
    {
        $teachers = $from->teachers()
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('id', $teacherIds))
            ->orderBy('name')
            ->get();
        $alreadyThere = $this->teacherIdsAlreadyIn($from, $to)->flip();
        $copiedIds = [];

        DB::transaction(function () use ($teachers, $alreadyThere, $from, $to, $withConstraints, &$copiedIds) {
            foreach ($teachers as $teacher) {
                if ($alreadyThere->has($teacher->id)) {
                    continue;
                }

                $copiedIds[$teacher->id] = $to->teachers()->create($teacher->only(self::TEACHER_FIELDS))->id;
            }

            if (! $withConstraints) {
                return;
            }

            foreach ($from->sessionTeacherConstraints()->whereIn('teacher_id', array_keys($copiedIds))->get() as $constraint) {
                SessionTeacherConstraint::create([
                    'exam_session_id' => $to->id,
                    'teacher_id' => $copiedIds[$constraint->teacher_id],
                    'is_excluded' => $constraint->is_excluded,
                    'min_duties' => $constraint->min_duties,
                    'max_duties' => $constraint->max_duties,
                    'unavailable_days' => $constraint->unavailable_days,
                ]);
            }
        });

        return ['copied' => count($copiedIds), 'skipped' => $teachers->count() - count($copiedIds)];
    }

    private function key(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
