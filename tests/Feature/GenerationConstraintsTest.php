<?php

namespace Tests\Feature;

use App\Livewire\Sessions\GenerationConstraints;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GenerationConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_save_generation_settings(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'mixed')
            ->set('mixed_subjects_per_room', 3)
            ->set('invigilators_per_room', 3)
            ->set('teacher_subject_exclusion', true)
            ->call('saveSettings');

        $this->assertSame('mixed', $session->fresh()->seating_strategy);
        $this->assertSame(3, $session->fresh()->mixed_subjects_per_room);
        $this->assertSame(3, $session->fresh()->invigilators_per_room);
        $this->assertTrue($session->fresh()->teacher_subject_exclusion);
    }

    public function test_staff_can_save_respect_room_capacity(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['respect_room_capacity' => false]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('respect_room_capacity', true)
            ->call('saveSettings');

        $this->assertTrue($session->fresh()->respect_room_capacity);
    }

    public function test_staff_can_save_one_of_the_new_overflow_seating_strategies(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict_overflow_subject')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('strict_overflow_subject', $session->fresh()->seating_strategy);
    }

    public function test_staff_can_save_the_combined_section_then_subject_overflow_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict_overflow_section_then_subject')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('strict_overflow_section_then_subject', $session->fresh()->seating_strategy);
    }

    public function test_mixed_subjects_per_room_resets_to_default_outside_mixed_mode(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict')
            ->set('mixed_subjects_per_room', 7)
            ->call('saveSettings');

        $this->assertSame(2, $session->fresh()->mixed_subjects_per_room);
    }

    public function test_status_overview_reflects_each_stages_progress(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->assertSet('examSession.id', $session->id);
        $this->assertSame(0, $component->viewData('missingTeacherCount'));
        $this->assertSame(0, $component->viewData('enrolledSubjectCount'));
        $this->assertFalse($component->viewData('hasSeating'));
        $this->assertFalse($component->viewData('hasDuties'));
    }
}
