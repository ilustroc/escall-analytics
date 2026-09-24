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
        Schema::table('analytics_imports', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analytics_imports', function (Blueprint $table) {
            $table->dropColumn(['file_size', 'rows_processed', 'progress', 'metadata']);
        });
    }
};
