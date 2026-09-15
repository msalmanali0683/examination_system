<?php

namespace Tests\Feature;

use App\Livewire\Sessions\TimeSlots;
use App\Models\ExamSession;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimeSlotBulkGenerateTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_the_configured_number_of_slots_across_multiple_dates(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['start_date' => '2026-05-04', 'end_date' => '2026-05-10']);

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->call('startBulkGenerate')
            ->set('bulkSlotsPerDay', 2)
            ->set('bulkSlotTimes.0.start', '09:00')
            ->set('bulkSlotTimes.0.end', '10:30')
            ->set('bulkSlotTimes.1.start', '11:30')
            ->set('bulkSlotTimes.1.end', '13:00')
            ->set('bulkStartDate', '2026-05-04')
            ->set('bulkDateCount', 3)
            ->call('generateBulkSlots');

        $this->assertDatabaseCount('time_slots', 6);

        $dates = TimeSlot::all()->map(fn (TimeSlot $s) => $s->date->toDateString().' '.substr($s->start_time, 0, 5).'-'.substr($s->end_time, 0, 5))->sort()->values();

        $this->assertTrue($dates->contains('2026-05-04 09:00-10:30'));
        $this->assertTrue($dates->contains('2026-05-04 11:30-13:00'));
        $this->assertTrue($dates->contains('2026-05-06 11:30-13:00'));
        $this->assertDatabaseHas('activity_logs', [
            'exam_session_id' => $session->id,
            'action' => 'time_slots.bulk_generated',
        ]);
    }

    public function test_skips_the_selected_weekday_when_generating_dates(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        // 2026-05-04 is a Monday; asking for 7 dates while skipping Sunday
        // (iso 7) should land on 2026-05-04..09 then skip to 05-11.
        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->call('startBulkGenerate')
            ->set('bulkSlotsPerDay', 1)
            ->set('bulkSlotTimes.0.start', '09:00')
            ->set('bulkSlotTimes.0.end', '10:00')
            ->set('bulkStartDate', '2026-05-04')
            ->set('bulkDateCount', 7)
            ->call('toggleBulkSkipDay', 7)
            ->call('generateBulkSlots');

        $dates = TimeSlot::all()->map(fn (TimeSlot $s) => $s->date->toDateString())->sort()->values();

        $this->assertCount(7, $dates);
        $this->assertFalse($dates->contains('2026-05-10')); // the Sunday in range
        $this->assertTrue($dates->contains('2026-05-11')); // Monday after, picked up instead
    }

    public function test_generating_again_replaces_existing_slots_and_clears_dependent_pins(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $oldSlot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-01-01']);
        $subject = Subject::factory()->create();
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $oldSlot->id,
            'is_pinned' => true,
        ]);

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->call('startBulkGenerate')
            ->set('bulkSlotsPerDay', 1)
            ->set('bulkSlotTimes.0.start', '09:00')
            ->set('bulkSlotTimes.0.end', '10:00')
            ->set('bulkStartDate', '2026-06-01')
            ->set('bulkDateCount', 2)
            ->call('generateBulkSlots');

        $this->assertDatabaseMissing('time_slots', ['id' => $oldSlot->id]);
        $this->assertDatabaseCount('time_slots', 2);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => null,
            'is_pinned' => false,
        ]);
        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_at_least_one_weekday_must_remain_available(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->call('startBulkGenerate')
            ->set('bulkSlotsPerDay', 1)
            ->set('bulkSlotTimes.0.start', '09:00')
            ->set('bulkSlotTimes.0.end', '10:00')
            ->set('bulkStartDate', '2026-06-01')
            ->set('bulkDateCount', 3);

        foreach (range(1, 7) as $day) {
            $component->call('toggleBulkSkipDay', $day);
        }

        $component->call('generateBulkSlots');

        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_changing_slots_per_day_resizes_the_timings_array_keeping_existing_values(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->call('startBulkGenerate')
            ->set('bulkSlotTimes.0.start', '08:00')
            ->set('bulkSlotTimes.0.end', '09:00')
            ->set('bulkSlotsPerDay', 3);

        $component->assertSet('bulkSlotTimes.0.start', '08:00');
        $component->assertSet('bulkSlotTimes.2.start', '');

        $component->set('bulkSlotsPerDay', 1);
        $component->assertCount('bulkSlotTimes', 1);
    }

    public function test_bulk_generate_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->set('bulkSlotsPerDay', 1)
            ->set('bulkSlotTimes.0.start', '09:00')
            ->set('bulkSlotTimes.0.end', '10:00')
            ->set('bulkStartDate', '2026-06-01')
            ->set('bulkDateCount', 2)
            ->call('generateBulkSlots');

        $this->assertDatabaseCount('time_slots', 0);
    }
}
