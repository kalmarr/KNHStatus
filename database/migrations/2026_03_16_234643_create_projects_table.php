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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->enum('type', ['http', 'ssl', 'api', 'ping', 'port', 'heartbeat']);
            $table->unsignedInteger('interval')->default(60);
            $table->json('monitor_config')->nullable();
            $table->json('channels')->default('["email"]');
            $table->foreignId('parent_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
