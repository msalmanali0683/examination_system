<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks a report (Excel/PDF) already generated and saved to disk for a
 * session, keyed by report type + the exact filter combination it was
 * built with — see ReportFileCache. Downloading the same report/filters
 * again serves this file instead of regenerating it; a large session's
 * Room-wise Seating Chart in particular can otherwise take long enough to
 * risk a timeout on every single download.
 */
class ReportFile extends Model
{
    protected $fillable = [
        'exam_session_id',
        'report_key',
        'filters_hash',
        'filters',
        'disk_path',
        'generated_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'generated_at' => 'datetime',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }
}
