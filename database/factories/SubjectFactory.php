<?php

namespace Database\Factories;

use App\Models\ExamSession;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
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
            'code' => 'CS'.fake()->unique()->numerify('#####').'|11',
            'title' => fake()->words(3, true),
            'exam_type' => 'theory',
        ];
    }
}
