<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'date',
        'start_time',
        'end_time',
        'label',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function getDayNameAttribute(): string
    {
        return $this->date->format('l');
    }
}
