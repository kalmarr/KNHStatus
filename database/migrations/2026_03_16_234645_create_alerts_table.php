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
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // incident_id FK constraint is added in 2026_03_16_234645_create_incidents_table
            // because alerts runs before incidents alphabetically within the same timestamp
            $table->foreignId('incident_id')->nullable()->index();
            $table->enum('channel', ['email', 'telegram', 'viber', 'webhook']);
            $table->enum('status', ['sent', 'failed', 'skipped'])->default('sent');
            $table->text('message');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['project_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
