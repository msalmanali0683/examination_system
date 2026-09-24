<?php

namespace Database\Factories;

use App\Models\ExamSession;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
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
            'roll_no' => (string) fake()->unique()->numerify('70#####'),
            'name' => fake()->name(),
            'program' => 'Bachelor of Science in Artificial Intelligence',
        ];
    }
}
