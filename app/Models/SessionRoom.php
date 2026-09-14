<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SessionRoom extends Pivot
{
    protected $table = 'session_rooms';

    public $incrementing = true;

    protected $fillable = [
        'exam_session_id',
        'room_id',
        'is_active',
        'capacity_override',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function effectiveCapacity(): int
    {
        return $this->capacity_override ?? $this->room->capacity;
    }
}
