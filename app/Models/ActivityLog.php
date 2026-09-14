<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'exam_session_id',
        'user_id',
        'action',
        'description',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function record(?ExamSession $session, string $action, string $description): self
    {
        return static::create([
            'exam_session_id' => $session?->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
