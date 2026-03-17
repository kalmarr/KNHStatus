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
        Schema::create('response_time_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('avg_ms');
            $table->unsignedInteger('min_ms');
            $table->unsignedInteger('max_ms');
            $table->unsignedInteger('p95_ms')->nullable();
            $table->unsignedInteger('p99_ms')->nullable();
            $table->unsignedInteger('total_checks');
            $table->unsignedInteger('successful_checks');
            $table->decimal('uptime_percent', 5, 2);
            $table->timestamps();

            $table->unique(['project_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('response_time_stats');
    }
};
