<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SessionTeacherConstraint extends Pivot
{
    protected $table = 'session_teacher_constraints';

    public $incrementing = true;

    protected $fillable = [
        'exam_session_id',
        'teacher_id',
        'is_excluded',
        'min_duties',
        'max_duties',
    ];

    protected $casts = [
        'is_excluded' => 'boolean',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function effectiveMinDuties(): int
    {
        return $this->min_duties ?? config('exam.default_min_duties');
    }

    public function effectiveMaxDuties(): int
    {
        return $this->max_duties ?? config('exam.default_max_duties');
    }
}
