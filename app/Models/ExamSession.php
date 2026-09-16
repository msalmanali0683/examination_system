<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use HasFactory;

    /**
     * Every valid `seating_strategy` value, with a human label — the
     * single source of truth for the strategy picker's <select> options
     * and the validation rule, so the two can never drift apart.
     */
    public const SEATING_STRATEGIES = [
        'strict' => 'Strict — one room per subject + section',
        'combine_sections' => 'Combine sections of the same subject',
        'strict_overflow_section' => 'Strict, fill leftover seats with another section of the same subject',
        'strict_overflow_subject' => 'Strict, fill leftover seats with a different subject',
        'strict_overflow_section_then_subject' => 'Strict, fill leftover seats with another section, then a different subject if none left',
        'combine_sections_overflow_subject' => 'Combine sections, fill leftover seats with a different subject',
        'mixed' => 'Mix different subjects (whole columns alternate)',
    ];

    protected $fillable = [
        'name',
        'department_name',
        'report_status',
        'report_version',
        'start_date',
        'end_date',
        'status',
        'seating_strategy',
        'mixed_subjects_per_room',
        'invigilators_per_room',
        'teacher_subject_exclusion',
        'respect_room_capacity',
        'ignored_missing_teacher_sections',
        'locked_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'teacher_subject_exclusion' => 'boolean',
        'respect_room_capacity' => 'boolean',
        'ignored_missing_teacher_sections' => 'array',
        'locked_at' => 'datetime',
    ];

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class);
    }

    public function sessionRooms(): HasMany
    {
        return $this->hasMany(SessionRoom::class);
    }

    public function sessionTeacherConstraints(): HasMany
    {
        return $this->hasMany(SessionTeacherConstraint::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function subjectSlotAssignments(): HasMany
    {
        return $this->hasMany(SubjectSlotAssignment::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class);
    }

    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'session_rooms')
            ->using(SessionRoom::class)
            ->withPivot(['id', 'is_active', 'capacity_override'])
            ->withTimestamps();
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'session_teacher_constraints')
            ->using(SessionTeacherConstraint::class)
            ->withPivot(['id', 'is_excluded', 'min_duties', 'max_duties'])
            ->withTimestamps();
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /**
     * The department name printed on this session's reports — falls back
     * to the app-wide default when the session hasn't set its own.
     */
    public function effectiveDepartmentName(): string
    {
        return $this->department_name ?: config('exam.department_name');
    }

    /**
     * The stamp printed on every report for this session, e.g.
     * "TENTATIVE — SUBJECT TO CHANGE", "TENTATIVE — v2 — SUBJECT TO
     * CHANGE", or "FINAL — v3".
     */
    public function reportStampLabel(): string
    {
        $label = strtoupper($this->report_status ?: 'tentative');

        if ($this->report_version) {
            $label .= ' — '.$this->report_version;
        }

        if (($this->report_status ?: 'tentative') === 'tentative') {
            $label .= ' — SUBJECT TO CHANGE';
        }

        return $label;
    }

    public function isReportFinal(): bool
    {
        return $this->report_status === 'final';
    }
}
