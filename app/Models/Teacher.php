<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
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

    public function sessionConstraints(): HasMany
    {
        return $this->hasMany(SessionTeacherConstraint::class);
    }
}
