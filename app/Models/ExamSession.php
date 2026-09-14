<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'seating_strategy',
        'mixed_subjects_per_room',
        'invigilators_per_room',
        'teacher_subject_exclusion',
        'locked_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'teacher_subject_exclusion' => 'boolean',
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
}
