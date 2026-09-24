<?php

namespace Database\Factories;

use App\Models\AnalyticsAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsAuditLog>
 */
class AnalyticsAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => 'login_success',
        ];
    }
}
