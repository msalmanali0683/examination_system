<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'name',
        'designation',
        'department',
        'email',
        'phone',
        'pernr',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    /**
     * Time slots this teacher can't do duty in (see SlotAvailability) —
     * finer than the whole-weekday rule on SessionTeacherConstraint.
     */
    public function unavailableSlots(): BelongsToMany
    {
        return $this->belongsToMany(TimeSlot::class, 'teacher_unavailable_slots')->withTimestamps();
    }

    public function sessionConstraints(): HasMany
    {
        return $this->hasMany(SessionTeacherConstraint::class);
    }

    public function taughtEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
