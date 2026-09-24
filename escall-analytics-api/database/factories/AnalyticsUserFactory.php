<?php

namespace Database\Factories;

use App\Models\AnalyticsUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AnalyticsUser>
 */
class AnalyticsUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('Password!12345'),
            'role' => AnalyticsUser::ROLE_PROVEEDOR,
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ];
    }
}
