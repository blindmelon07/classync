<?php

namespace Database\Factories;

use App\Models\TeacherEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherEmail>
 */
class TeacherEmailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
