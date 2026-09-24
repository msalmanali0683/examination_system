<?php

namespace Tests\Feature;

use App\Exports\AnswerSheetsExport;
use App\Livewire\Sessions\ReportDownloads;
use App\Livewire\Sessions\ReportShow;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AnswerSheetsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function slot(ExamSession $session, string $date, string $start = '09:00', string $end = '10:30'): TimeSlot
    {
        return TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    /**
     * A subject with $students enrolled (split across two sections when
     * $sections > 1), optionally sitting in $slot.
     */
    private function subject(ExamSession $session, string $title, int $students, ?TimeSlot $slot = null, int $sections = 1, bool $excluded = false): Subject
    {
        $subject = Subject::factory()->for($session)->create(['title' => $title]);

        for ($i = 0; $i < $students; $i++) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => Student::factory()->for($session),
                'subject_id' => $subject->id,
                'section' => 'BSAI 1'.chr(65 + ($i % $sections)),
            ]);
        }

        if ($slot || $excluded) {
            SubjectSlotAssignment::create([
                'exam_session_id' => $session->id,
                'subject_id' => $subject->id,
                'time_slot_id' => $slot?->id,
                'is_excluded' => $excluded,
            ]);
        }

        return $subject;
    }

    public function test_each_slot_totals_its_subjects_and_the_grand_total_adds_every_slot(): void
    {
        $session = ExamSession::factory()->create();
        $slot1 = $this->slot($session, '2026-04-20', '09:00', '10:30');
        $slot2 = $this->slot($session, '2026-04-21', '09:00', '10:30');
        $this->subject($session, 'Machine Learning', 120, $slot1);
        $this->subject($session, 'Artificial Intelligence', 80, $slot1);
        $this->subject($session, 'Databases', 45, $slot2);

        $report = (new ReportDataBuilder)->answerSheetRows($session);

        $this->assertCount(2, $report->slots);
        $this->assertSame(200, $report->slots[0]->sheets);
        $this->assertSame(200, $report->slots[0]->students);
        $this->assertSame(['Artificial Intelligence' => 80, 'Machine Learning' => 120], $report->slots[0]->subjects->pluck('students', 'title')->all());
        $this->assertSame(45, $report->slots[1]->sheets);
        $this->assertSame(245, $report->grandTotal);
    }

    public function test_slots_are_numbered_in_date_and_time_order_not_creation_order(): void
    {
        $session = ExamSession::factory()->create();
        $later = $this->slot($session, '2026-04-21', '09:00', '10:30');
        $second = $this->slot($session, '2026-04-20', '13:00', '14:30');
        $first = $this->slot($session, '2026-04-20', '09:00', '10:30');
        $this->subject($session, 'Later', 1, $later);
        $this->subject($session, 'Second', 1, $second);
        $this->subject($session, 'First', 1, $first);

        $report = (new ReportDataBuilder)->answerSheetRows($session);

        $this->assertSame(['First', 'Second', 'Later'], $report->slots->map(fn ($slot) => $slot->subjects->first()->title)->all());
        $this->assertSame([1, 2, 3], $report->slots->pluck('number')->all());
    }

    public function test_a_subjects_sections_all_count_toward_its_sheets(): void
    {
        $session = ExamSession::factory()->create();
        $slot = $this->slot($session, '2026-04-20');
        $this->subject($session, 'Programming', 7, $slot, sections: 3);

        $report = (new ReportDataBuilder)->answerSheetRows($session);

        $this->assertSame(7, $report->grandTotal);
    }

    public function test_excluded_subjects_empty_subjects_and_unused_slots_are_left_out(): void
    {
        $session = ExamSession::factory()->create();
        $slot1 = $this->slot($session, '2026-04-20');
        $unused = $this->slot($session, '2026-04-21');
        $slot3 = $this->slot($session, '2026-04-22');
        $this->subject($session, 'Sitting', 10, $slot1);
        $this->subject($session, 'Excluded', 99, $slot1, excluded: true);
        $this->subject($session, 'Nobody Enrolled', 0, $unused);
        $this->subject($session, 'Last', 5, $slot3);

        $report = (new ReportDataBuilder)->answerSheetRows($session);

        $this->assertSame(15, $report->grandTotal);
        // The time slot nobody sits in doesn't take a number.
        $this->assertSame([1, 2], $report->slots->pluck('number')->all());
        $this->assertSame(['Sitting'], $report->slots[0]->subjects->pluck('title')->all());
    }

    public function test_enrolled_subjects_missing_from_the_timetable_are_flagged_not_silently_dropped(): void
    {
        $session = ExamSession::factory()->create();
        $slot = $this->slot($session, '2026-04-20');
        $this->subject($session, 'Scheduled', 10, $slot);
        $this->subject($session, 'Not Placed Yet', 6);
        $this->subject($session, 'Deliberately Excluded', 9, excluded: true);

        $report = (new ReportDataBuilder)->answerSheetRows($session);

        $this->assertSame(10, $report->grandTotal);
        $this->assertSame(1, $report->unscheduledSubjects);
        $this->assertSame(6, $report->unscheduledStudents);

        $html = (new AnswerSheetsExport($session))->view()->render();
        $this->assertStringContainsString('1 enrolled subject (6 students) is not on the timetable yet', $html);
    }

    public function test_filtering_to_a_day_or_slot_keeps_slot_numbers_and_totals_only_that_selection(): void
    {
        $session = ExamSession::factory()->create();
        $slot1 = $this->slot($session, '2026-04-20', '09:00', '10:30');
        $slot2 = $this->slot($session, '2026-04-20', '13:00', '14:30');
        $slot3 = $this->slot($session, '2026-04-21', '09:00', '10:30');
        $this->subject($session, 'A', 10, $slot1);
        $this->subject($session, 'B', 20, $slot2);
        $this->subject($session, 'C', 30, $slot3);

        $byDay = (new ReportDataBuilder)->answerSheetRows($session, '2026-04-20');
        $this->assertSame([1, 2], $byDay->slots->pluck('number')->all());
        $this->assertSame(30, $byDay->grandTotal);

        $bySlot = (new ReportDataBuilder)->answerSheetRows($session, null, [$slot3->id]);
        $this->assertSame([3], $bySlot->slots->pluck('number')->all());
        $this->assertSame(30, $bySlot->grandTotal);
        // Narrowed reports don't nag about subjects outside the selection.
        $this->assertSame(0, $bySlot->unscheduledSubjects);
    }

    public function test_the_report_only_counts_its_own_session(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        $this->subject($session, 'Mine', 4, $this->slot($session, '2026-04-20'));
        $this->subject($other, 'Theirs', 50, $this->slot($other, '2026-04-20'));

        $this->assertSame(4, (new ReportDataBuilder)->answerSheetRows($session)->grandTotal);
    }

    public function test_the_sheet_prints_every_slot_total_and_the_grand_total(): void
    {
        $session = ExamSession::factory()->create();
        $morning = $this->slot($session, '2026-04-20', '09:00', '10:30');
        $this->subject($session, 'Machine Learning', 120, $morning);
        $this->subject($session, 'Artificial Intelligence', 80, $morning);
        $this->subject($session, 'Databases', 45, $this->slot($session, '2026-04-21', '13:00', '14:30'));

        $html = (new AnswerSheetsExport($session))->view()->render();

        foreach (['Machine Learning', 'Artificial Intelligence', 'Databases', 'Slot 1 total', 'Slot 2 total', 'GRAND TOTAL (2 slots)', '20-04-2026', 'Monday', '09:00 - 10:30'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertMatchesRegularExpression('/Slot 1 total<\/td>\s*<td[^>]*>200<\/td>\s*<td[^>]*>200<\/td>/', $html);
        $this->assertMatchesRegularExpression('/GRAND TOTAL \(2 slots\)<\/td>\s*<td[^>]*>245<\/td>\s*<td[^>]*>245<\/td>/', $html);
    }

    public function test_it_works_with_a_timetable_alone_no_seating_needed(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $this->subject($session, 'Machine Learning', 3, $this->slot($session, '2026-04-20'));

        $this->assertDatabaseCount('seat_assignments', 0);

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'answer-sheets'])
            ->assertSee('Excel')
            ->assertSee('PDF')
            ->assertDontSee('Nothing to report yet');
    }

    public function test_the_report_is_unavailable_until_subjects_are_on_slots(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $this->subject($session, 'Unplaced', 3);

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'answer-sheets'])
            ->assertSee('Nothing to report yet')
            ->assertSee('Put subjects on time slots first');

        $reportTypes = Livewire::actingAs($staff)
            ->test(ReportDownloads::class, ['examSession' => $session])
            ->viewData('reportTypes');
        $this->assertFalse($reportTypes['answer-sheets']['available']);
        // Seating-based reports keep their own gate.
        $this->assertFalse($reportTypes['seating-chart']['available']);
    }

    public function test_the_reports_menu_lists_it_and_unlocks_it_once_a_subject_is_scheduled(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $this->subject($session, 'Machine Learning', 3, $this->slot($session, '2026-04-20'));

        $component = Livewire::actingAs($staff)->test(ReportDownloads::class, ['examSession' => $session]);

        $component->assertSee('Answer Sheets Required');
        $this->assertTrue($component->viewData('reportTypes')['answer-sheets']['available']);
    }

    public function test_excel_and_pdf_download(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $this->subject($session, 'Machine Learning', 3, $this->slot($session, '2026-04-20'));

        $excel = $this->actingAs($staff)->get(route('sessions.reports.answer-sheets.xlsx', $session));
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('content-type'));

        $pdf = $this->actingAs($staff)->get(route('sessions.reports.answer-sheets.pdf', $session));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_a_user_without_view_reports_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'view_reports', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)->get(route('sessions.reports.answer-sheets.xlsx', $session))->assertForbidden();
        $this->actingAs($staff)->get(route('sessions.reports.answer-sheets.pdf', $session))->assertForbidden();
    }
}
