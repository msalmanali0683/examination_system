<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectSlotAssignment extends Model
{
    protected $fillable = [
        'exam_session_id',
        'subject_id',
        'time_slot_id',
        'is_pinned',
        'conflict_note',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }
}
