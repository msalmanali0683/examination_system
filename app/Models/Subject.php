<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'credit_hours',
        'exam_type',
        'merged_into_id',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * The subject this one was merged into, if any — see
     * SubjectMergeService. The merged-away subject's own row is kept
     * (never deleted), since finalized sessions may still reference it.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'merged_into_id');
    }

    /**
     * Every subject that was merged into this one.
     */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(Subject::class, 'merged_into_id');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /**
     * Follows the merge chain to the subject still actively used for new
     * enrollments — merging into an already-merged subject isn't offered
     * by the UI, so this is normally a single hop, but it walks the full
     * chain in case that ever changes.
     */
    public function canonical(): self
    {
        $subject = $this;

        while ($subject->merged_into_id !== null) {
            $subject = $subject->mergedInto;
        }

        return $subject;
    }
}
