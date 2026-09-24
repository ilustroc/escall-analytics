<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || $this->indexExists()) {
            return;
        }

        Schema::table('gestiones', function (Blueprint $table): void {
            $table->index(['fecha_gestion', 'id'], 'idx_gestiones_fecha_gestion_id');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || ! $this->indexExists()) {
            return;
        }

        Schema::table('gestiones', function (Blueprint $table): void {
            $table->dropIndex('idx_gestiones_fecha_gestion_id');
        });
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('gestiones'))->contains(
            static fn (array $index): bool => $index['name'] === 'idx_gestiones_fecha_gestion_id',
        );
    }
};
