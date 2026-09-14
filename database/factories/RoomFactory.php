<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Room '.fake()->unique()->numerify('###'),
            'rows' => 10,
            'columns' => 5,
            'capacity' => 50,
            'room_type' => 'regular',
            'is_active' => true,
        ];
    }
}
