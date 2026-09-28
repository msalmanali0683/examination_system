<?php

namespace Tests\Feature;

use App\Livewire\Sessions\ReportShow;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reports\ReportFileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A cached report file is only served while the schedule it was built from is unchanged. Before this, a datesheet
 * downloaded after a seat was moved, a duty reassigned or the session marked "Final" was the OLD file, still
 * reading "TENTATIVE" — silently wrong paperwork.
 */
class ReportStalenessTest extends TestCase
{
    use RefreshDatabase;

    private ExamSession $session;

    private Room $room;

    private TimeSlot $slot;

    private SeatAssignment $seat;

    private DutyAssignment $duty;

    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->session = ExamSession::factory()->create(['report_status' => 'tentative']);
        $this->room = Room::factory()->for($this->session)->create(['name' => 'R-1']);
        $this->slot = TimeSlot::factory()->create(['exam_session_id' => $this->session->id]);
        $subject = Subject::factory()->for($this->session)->create();
        $student = Student::factory()->for($this->session)->create();
        $enrollment = Enrollment::factory()->create(['exam_session_id' => $this->session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $this->session->id, 'subject_id' => $subject->id, 'time_slot_id' => $this->slot->id]);
        $this->seat = SeatAssignment::create(['exam_session_id' => $this->session->id, 'enrollment_id' => $enrollment->id, 'time_slot_id' => $this->slot->id, 'room_id' => $this->room->id, 'row_number' => 1, 'column_number' => 1]);
        $this->duty = DutyAssignment::create(['exam_session_id' => $this->session->id, 'teacher_id' => Teacher::factory()->for($this->session)->create()->id, 'time_slot_id' => $this->slot->id, 'room_id' => $this->room->id]);
    }

    /** Asks the cache for the report and reports whether it had to build it (true) or served the saved file (false). */
    private function built(): bool
    {
        $before = $this->writes;

        (new ReportFileCache)->remember($this->session->fresh(), 'datesheet.xlsx', ['date' => null, 'slots' => null, 'showInvigilators' => true], function (string $path) {
            $this->writes++;
            Storage::disk('local')->put($path, 'file #'.$this->writes);
        });

        return $this->writes > $before;
    }

    public function test_an_unchanged_schedule_is_served_from_the_saved_file(): void
    {
        $this->assertTrue($this->built(), 'the first request builds it');
        $this->assertFalse($this->built(), 'the second is served from disk');
        $this->assertFalse($this->built());
        $this->assertSame(1, $this->writes);
    }

    public function test_moving_a_seat_rebuilds_the_report(): void
    {
        $this->built();

        $this->seat->update(['row_number' => 2, 'is_locked' => true]);

        $this->assertTrue($this->built());
        $this->assertFalse($this->built(), 'and the fresh file is then cached again');
    }

    public function test_swapping_two_students_seats_inside_the_same_second_is_still_detected(): void
    {
        $subject = Subject::where('exam_session_id', $this->session->id)->first();
        $other = Enrollment::factory()->create(['exam_session_id' => $this->session->id, 'student_id' => Student::factory()->for($this->session)->create()->id, 'subject_id' => $subject->id]);
        $second = SeatAssignment::create(['exam_session_id' => $this->session->id, 'enrollment_id' => $other->id, 'time_slot_id' => $this->slot->id, 'room_id' => $this->room->id, 'row_number' => 2, 'column_number' => 1]);

        $this->built();

        // no clock movement: the swap happens in the same second the seats were created
        SeatAssignment::whereKey($this->seat->id)->update(['row_number' => 3, 'column_number' => 3]); // out of the way
        SeatAssignment::whereKey($second->id)->update(['row_number' => 1, 'column_number' => 1]);
        SeatAssignment::whereKey($this->seat->id)->update(['row_number' => 2, 'column_number' => 1]);

        $this->assertTrue($this->built());
    }

    public function test_reassigning_a_duty_rebuilds_the_report(): void
    {
        $this->built();

        $this->duty->update(['teacher_id' => Teacher::factory()->for($this->session)->create()->id]);

        $this->assertTrue($this->built());
    }

    public function test_regenerating_seating_rebuilds_the_report_even_when_it_ends_up_identical(): void
    {
        $this->built();

        $again = $this->seat->replicate();
        $this->seat->delete();
        $again->save();

        $this->assertTrue($this->built(), 'delete + re-insert changes the row ids, which the stamp notices');
    }

    public function test_marking_the_session_final_rebuilds_the_report(): void
    {
        $this->built();

        $this->session->update(['report_status' => 'final', 'report_version' => 'v2']);

        $this->assertTrue($this->built());
    }

    public function test_renaming_a_room_a_teacher_a_subject_or_a_student_rebuilds_the_report(): void
    {
        foreach ([
            fn () => $this->room->update(['name' => 'R-1 renamed']),
            fn () => $this->duty->teacher->update(['name' => 'Someone Else']),
            fn () => Subject::where('exam_session_id', $this->session->id)->first()->update(['title' => 'New Title']),
            fn () => Student::where('exam_session_id', $this->session->id)->first()->update(['name' => 'New Name']),
        ] as $change) {
            $this->built();
            $this->assertFalse($this->built());
            $this->travel(2)->seconds(); // updated_at has one-second resolution
            $change();
            $this->assertTrue($this->built());
        }
    }

    public function test_removing_a_time_slot_or_an_enrollment_rebuilds_the_report(): void
    {
        $this->built();
        Enrollment::where('exam_session_id', $this->session->id)->delete();
        $this->assertTrue($this->built());
    }

    public function test_finalizing_or_unlocking_does_not_needlessly_rebuild(): void
    {
        $this->built();

        $this->session->update(['status' => 'finalized', 'locked_at' => now()]);
        $this->assertFalse($this->built());

        $this->session->update(['status' => 'generated', 'locked_at' => null]);
        $this->assertFalse($this->built());
    }

    public function test_another_sessions_changes_never_invalidate_this_ones_reports(): void
    {
        $this->built();

        $other = ExamSession::factory()->create();
        Room::factory()->for($other)->create();
        Teacher::factory()->for($other)->create();

        $this->assertFalse($this->built());
    }

    public function test_a_legacy_row_without_a_stamp_counts_as_out_of_date(): void
    {
        $this->built();
        ReportFile::query()->update(['data_stamp' => null]);

        $this->assertTrue($this->built());
    }

    public function test_the_download_route_never_serves_an_outdated_file(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $url = route('sessions.reports.simple-datesheet.xlsx', $this->session);

        $this->actingAs($head)->get($url); // builds (queue runs synchronously in tests) and/or redirects
        $first = ReportFile::where('exam_session_id', $this->session->id)->where('report_key', 'simple-datesheet.xlsx')->first();
        $this->assertNotNull($first);
        $this->assertSame(ReportFile::STATUS_READY, $first->status);
        $stampBefore = $first->data_stamp;

        $this->travel(2)->seconds();
        $this->session->update(['report_status' => 'final']);

        $this->actingAs($head)->get($url);
        $second = $first->fresh();
        $this->assertSame(ReportFile::STATUS_READY, $second->status);
        $this->assertNotSame($stampBefore, $second->data_stamp, 'the file was rebuilt for the changed session');
    }

    public function test_the_report_page_says_when_a_file_is_out_of_date(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $this->built();
        ReportFile::query()->update(['report_key' => 'simple-datesheet.xlsx', 'filters' => ['date' => null, 'slots' => null, 'showInvigilators' => true], 'filters_hash' => md5(json_encode(['date' => null, 'showInvigilators' => true, 'slots' => null]))]);
        ReportFile::query()->update(['data_stamp' => (new ReportFileCache)->currentStamp($this->session)]);

        $page = Livewire::actingAs($head)->test(ReportShow::class, ['examSession' => $this->session, 'reportType' => 'simple-datesheet']);
        $page->assertSee('Generated')->assertDontSee('Out of date');

        $this->travel(2)->seconds();
        $this->seat->update(['row_number' => 3]);

        Livewire::actingAs($head)->test(ReportShow::class, ['examSession' => $this->session, 'reportType' => 'simple-datesheet'])
            ->assertSee('Out of date')
            ->assertSee('Rebuild now');
    }
}
