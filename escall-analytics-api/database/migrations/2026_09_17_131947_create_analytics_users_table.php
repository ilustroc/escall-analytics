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
        Schema::create('analytics_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('analytics_users')->nullOnDelete();
            $table->index(['role', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_users');
    }
};
