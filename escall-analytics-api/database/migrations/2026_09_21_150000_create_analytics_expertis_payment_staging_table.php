<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_expertis_payment_staging', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('analytics_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('fecha_pago')->nullable();
            $table->char('period', 6)->nullable();
            $table->string('codigo', 100)->nullable();
            $table->string('codigo_normalizado', 100)->nullable();
            $table->string('dni', 20)->nullable();
            $table->decimal('monto', 14, 2)->nullable();
            $table->string('ejecutivo', 150)->nullable();
            $table->string('tipo_acuerdo', 3)->nullable();
            $table->char('logical_hash', 64)->nullable();
            $table->boolean('is_valid')->default(false);
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'is_valid']);
            $table->index(['import_id', 'period']);
            $table->index(['import_id', 'logical_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_expertis_payment_staging');
    }
};
