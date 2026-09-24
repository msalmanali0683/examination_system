<?php

namespace Database\Factories;

use App\Models\ExamSession;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_session_id' => ExamSession::factory(),
            'name' => fake()->name(),
            'designation' => 'Lecturer',
            'department' => 'Software Engineering',
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }
}
