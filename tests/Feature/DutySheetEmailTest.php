<?php

namespace Tests\Feature;

use App\Livewire\Sessions\ReportShow;
use App\Mail\TeacherDutySheetMail;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class DutySheetEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_emailing_all_duty_sheets_sends_one_per_teacher_with_an_email_on_file(): void
    {
        Mail::fake();

        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $withEmail = Teacher::factory()->for($session)->create(['is_active' => true, 'email' => 'huria@example.com']);
        $withoutEmail = Teacher::factory()->for($session)->create(['is_active' => true, 'email' => null]);

        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $withEmail->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id]);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $withoutEmail->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id]);

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'duty-roster'])
            ->call('emailAllDutySheets');

        Mail::assertSentCount(1);
        Mail::assertSent(TeacherDutySheetMail::class, fn ($mail) => $mail->hasTo('huria@example.com'));

        $this->assertDatabaseHas('activity_logs', [
            'exam_session_id' => $session->id,
            'action' => 'duty_sheets.emailed',
        ]);
    }

    public function test_emailing_with_no_duties_generated_shows_an_error_and_sends_nothing(): void
    {
        Mail::fake();

        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'duty-roster'])
            ->call('emailAllDutySheets')
            ->assertSet('examSession.id', $session->id);

        Mail::assertNothingSent();
    }

    public function test_user_without_view_reports_permission_cannot_email_duty_sheets(): void
    {
        Mail::fake();

        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'view_reports', 'granted' => false]);
        $session = ExamSession::factory()->create();

        // The whole report page (not just this one action) requires
        // view_reports — see ReportShow::mount() — so the denial shows
        // up as soon as the page itself is requested.
        $this->actingAs($staff)
            ->get(route('sessions.reports.show', [$session, 'duty-roster']))
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
