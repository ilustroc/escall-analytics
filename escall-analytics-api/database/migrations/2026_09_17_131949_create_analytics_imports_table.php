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
        Schema::create('analytics_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->char('period', 6);
            $table->string('original_filename', 255);
            $table->string('stored_filename', 255)->nullable();
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('rows_total')->default(0);
            $table->unsignedBigInteger('rows_inserted')->default(0);
            $table->unsignedBigInteger('rows_rejected')->default(0);
            $table->string('status', 30);
            $table->text('error_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('analytics_users')->nullOnDelete();
            $table->index(['type', 'period']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_imports');
    }
};
