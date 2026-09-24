<?php

namespace Database\Factories;

use App\Models\AnalyticsImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsImport>
 */
class AnalyticsImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'expertis_asignacion',
            'period' => '202609',
            'original_filename' => 'asignacion.csv',
            'stored_filename' => 'analytics-imports/example.csv',
            'file_sha256' => str_repeat('a', 64),
            'file_size' => 1024,
            'rows_total' => 0,
            'rows_processed' => 0,
            'rows_inserted' => 0,
            'rows_rejected' => 0,
            'progress' => 0,
            'status' => 'validated',
        ];
    }
}
