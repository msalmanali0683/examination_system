<?php

namespace Database\Factories;

use App\Models\ExamSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSession>
 */
class ExamSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Midterm '.fake()->unique()->word().' '.fake()->year(),
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
            'status' => 'draft',
            'seating_strategy' => 'strict',
            'mixed_subjects_per_room' => 2,
            'invigilators_per_room' => 2,
            'teacher_subject_exclusion' => false,
            'respect_room_capacity' => false,
        ];
    }
}
