<?php

namespace App\Services\Generation\DTOs;

final class ConflictReportRow
{
    public function __construct(
        public readonly int $subjectId,
        public readonly string $subjectLabel,
        public readonly int $conflictingSubjectId,
        public readonly string $conflictingSubjectLabel,
        public readonly int $sharedStudentCount,
        public readonly string $message,
    ) {
    }
}
