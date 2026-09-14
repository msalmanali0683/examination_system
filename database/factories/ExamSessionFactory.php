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
        ];
    }
}
