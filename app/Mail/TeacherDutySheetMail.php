<?php

namespace App\Mail;

use App\Models\ExamSession;
use App\Models\Teacher;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherDutySheetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Teacher $teacher,
        public readonly ExamSession $examSession,
        public readonly string $pdfBinary,
        public readonly int $dutyCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Invigilation Duties — {$this->examSession->name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.teacher-duty-sheet');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, 'duty-sheet.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
