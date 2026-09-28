<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_session_id',
        'name',
        'rows',
        'columns',
        'capacity',
        'room_type',
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
     * Time slots this room can't be used in (see SlotAvailability).
     */
    public function unavailableSlots(): BelongsToMany
    {
        return $this->belongsToMany(TimeSlot::class, 'room_unavailable_slots')->withTimestamps();
    }
}
