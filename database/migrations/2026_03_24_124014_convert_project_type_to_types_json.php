<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert projects.type (enum) to projects.types (JSON array).
 * Add checks.monitor_type to track which monitor produced each check result.
 *
 * Meglévő adatok migrálása: az egyes type értéket JSON tömbbé konvertáljuk,
 * a checks táblában pedig a projekt akkori típusát backfill-eljük.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Új types JSON oszlop hozzáadása
        Schema::table('projects', function (Blueprint $table) {
            $table->json('types')->nullable()->after('type');
        });

        // 2. Meglévő type → types JSON tömb konvertálás
        DB::table('projects')->orderBy('id')->each(function ($project) {
            DB::table('projects')
                ->where('id', $project->id)
                ->update(['types' => json_encode([$project->type])]);
        });

        // 3. types NOT NULL-ra állítása
        Schema::table('projects', function (Blueprint $table) {
            $table->json('types')->nullable(false)->change();
        });

        // 4. Régi type oszlop törlése
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        // 5. checks.monitor_type oszlop hozzáadása
        Schema::table('checks', function (Blueprint $table) {
            $table->string('monitor_type', 20)->nullable()->after('project_id');
        });

        // 6. Meglévő checks backfill: a projekt akkori típusa alapján
        DB::statement('
            UPDATE checks c
            INNER JOIN projects p ON c.project_id = p.id
            SET c.monitor_type = JSON_UNQUOTE(JSON_EXTRACT(p.types, "$[0]"))
        ');
    }

    public function down(): void
    {
        // 1. type enum oszlop visszaállítása
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('type', ['http', 'ssl', 'api', 'ping', 'port', 'heartbeat'])
                ->default('http')
                ->after('url');
        });

        // 2. types JSON → type enum konvertálás (első elem)
        DB::table('projects')->orderBy('id')->each(function ($project) {
            $types = json_decode($project->types, true);
            DB::table('projects')
                ->where('id', $project->id)
                ->update(['type' => $types[0] ?? 'http']);
        });

        // 3. types oszlop törlése
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('types');
        });

        // 4. checks.monitor_type oszlop törlése
        Schema::table('checks', function (Blueprint $table) {
            $table->dropColumn('monitor_type');
        });
    }
};
