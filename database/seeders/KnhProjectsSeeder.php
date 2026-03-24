<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Seed initial KNH monitored projects.
 */
class KnhProjectsSeeder extends Seeder
{
    public function run(): void
    {
        // Contabo DE szerver — parent projekt a smart grouping-hoz
        $contaboServer = Project::create([
            'name' => 'Contabo DE #1',
            'url' => '158.220.111.143', // Contabo DE szerver IP (srv.knh.hu)
            'types' => ['ping'],
            'interval' => 60,
            'channels' => ['email', 'telegram'],
            'active' => true,
        ]);

        // Dentállás.hu — HTTP monitor
        Project::create([
            'name' => 'Dentállás.hu',
            'url' => 'https://dentallas.hu',
            'types' => ['http'],
            'interval' => 60,
            'monitor_config' => ['keyword' => 'fogorvos'], // kulcsszó keresés az oldalon
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // StayWizard.hu — HTTP monitor
        Project::create([
            'name' => 'StayWizard.hu',
            'url' => 'https://staywizard.hu',
            'types' => ['http'],
            'interval' => 60,
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // StayWizard API — egyszerű health check (nincs auth)
        Project::create([
            'name' => 'StayWizard API',
            'url' => 'https://staywizard.hu/api/v1/health',
            'types' => ['api'],
            'interval' => 60,
            'monitor_config' => [
                'expected_values' => ['status' => 'healthy'],
            ],
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // StayWizard Detailed Health — részletes diagnosztika (X-API-Key + X-API-Secret)
        Project::create([
            'name' => 'StayWizard Detailed Health',
            'url' => 'https://staywizard.hu/api/v1/health/detailed',
            'types' => ['api'],
            'interval' => 300,
            'monitor_config' => [
                'headers' => [
                    'X-API-Key' => 'CHANGE_ME',
                    'X-API-Secret' => 'CHANGE_ME',
                ],
                'expected_keys' => ['status', 'checks.database', 'checks.cache', 'checks.disk'],
                'expected_values' => [
                    'status' => 'healthy',
                    'checks.database' => 'ok',
                    'checks.cache' => 'ok',
                    'checks.disk' => 'ok',
                ],
                'max_response_ms' => 500,
            ],
            'channels' => ['email', 'telegram', 'viber'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // StayWizard Core Health — Booking Core proxy (Bearer token)
        Project::create([
            'name' => 'StayWizard Core Health',
            'url' => 'https://staywizard.hu/api/v1/core-health',
            'types' => ['api'],
            'interval' => 300,
            'monitor_config' => [
                'bearer_token' => 'CHANGE_ME',
                'expected_keys' => ['status'],
            ],
            'channels' => ['email', 'telegram', 'viber'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // Dentállás.hu SSL — SSL monitor
        Project::create([
            'name' => 'Dentállás.hu SSL',
            'url' => 'dentallas.hu',
            'types' => ['ssl'],
            'interval' => 3600, // óránként elég
            'monitor_config' => ['warn_days' => 14, 'critical_days' => 7],
            'channels' => ['email'],
            'active' => true,
        ]);

        // Contabo szerver SSH port — Port monitor
        Project::create([
            'name' => 'Contabo DE #1 SSH',
            'url' => '158.220.111.143',
            'types' => ['port'],
            'interval' => 300, // 5 percenként
            'monitor_config' => ['port' => 22],
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);
    }
}
