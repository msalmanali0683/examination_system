<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function sessionConstraints(): HasMany
    {
        return $this->hasMany(SessionTeacherConstraint::class);
    }

    public function taughtEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
