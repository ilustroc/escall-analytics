<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_expertis_assignment_staging', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('analytics_imports')->cascadeOnDelete();
            $table->char('period', 6);
            $table->bigInteger('IdDeudor')->nullable();
            $table->string('Documento_RUC', 20)->nullable();
            $table->string('CARTERA', 100)->nullable();
            $table->string('NombreDeudor', 255)->nullable();
            $table->string('conca', 255)->nullable();
            $table->string('cosecha', 100)->nullable();
            $table->string('Canal', 100)->nullable();
            $table->string('MOVIMIENTO CANAL', 100)->nullable();
            $table->string('Grupo', 100)->nullable();
            $table->string('CodigoGrupo', 100)->nullable();
            $table->string('Segmento', 100)->nullable();
            $table->string('Subsegmento', 100)->nullable();
            $table->decimal('DK', 18, 2)->nullable();
            $table->decimal('DT', 18, 2)->nullable();
            $table->string('RANGO_DEUDA', 100)->nullable();
            $table->string('antiguedad', 100)->nullable();
            $table->date('fechaCastigo')->nullable();
            $table->decimal('C1', 18, 2)->nullable();
            $table->decimal('C2', 18, 2)->nullable();
            $table->decimal('C3', 18, 2)->nullable();
            $table->decimal('C6', 18, 2)->nullable();
            $table->decimal('C12', 18, 2)->nullable();
            $table->decimal('C24', 18, 2)->nullable();
            $table->decimal('nvl_Asesor', 18, 2)->nullable();
            $table->decimal('nvl_Supervisor', 18, 2)->nullable();
            $table->decimal('nvl_Jefe', 18, 2)->nullable();
            $table->decimal('nvl_Gerente', 18, 2)->nullable();
            $table->string('nvl_Socio', 100)->nullable();
            $table->string('CEF_INICIO', 100)->nullable();
            $table->integer('Percentil')->nullable();
            $table->string('telef1cef', 20)->nullable();
            $table->string('telef2cef', 20)->nullable();
            $table->string('telef1prim', 20)->nullable();
            $table->string('telef2prim', 20)->nullable();
            $table->string('telef1yp', 20)->nullable();
            $table->string('telef2yp', 20)->nullable();
            $table->string('telef1admin', 20)->nullable();
            $table->string('telef2admin', 20)->nullable();
            $table->string('telef1search', 20)->nullable();
            $table->string('telef2search', 20)->nullable();
            $table->timestamps();

            $table->index('import_id');
            $table->unique(['import_id', 'conca']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_expertis_assignment_staging');
    }
};
