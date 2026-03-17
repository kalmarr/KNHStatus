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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['down', 'anomaly', 'ssl_expiry'])->default('down');
            $table->enum('severity', ['critical', 'warning', 'info'])->default('critical');
            $table->text('title');
            $table->text('description')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'resolved_at']);
        });

        // Add deferred FK constraints on checks and alerts tables now that incidents exists
        Schema::table('checks', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('incidents')->nullOnDelete();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('incidents')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
        });

        Schema::dropIfExists('incidents');
    }
};
