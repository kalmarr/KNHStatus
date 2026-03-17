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
            'type' => 'ping',
            'interval' => 60,
            'channels' => ['email', 'telegram'],
            'active' => true,
        ]);

        // Dentállás.hu — HTTP monitor
        Project::create([
            'name' => 'Dentállás.hu',
            'url' => 'https://dentallas.hu',
            'type' => 'http',
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
            'type' => 'http',
            'interval' => 60,
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // StayWizard API — API monitor
        Project::create([
            'name' => 'StayWizard API',
            'url' => 'https://api.staywizard.hu/health',
            'type' => 'api',
            'interval' => 120,
            'monitor_config' => [
                'expected_keys' => ['status'],
                'expected_values' => ['status' => 'ok'],
            ],
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);

        // Dentállás.hu SSL — SSL monitor
        Project::create([
            'name' => 'Dentállás.hu SSL',
            'url' => 'dentallas.hu',
            'type' => 'ssl',
            'interval' => 3600, // óránként elég
            'monitor_config' => ['warn_days' => 14, 'critical_days' => 7],
            'channels' => ['email'],
            'active' => true,
        ]);

        // Contabo szerver SSH port — Port monitor
        Project::create([
            'name' => 'Contabo DE #1 SSH',
            'url' => '158.220.111.143',
            'type' => 'port',
            'interval' => 300, // 5 percenként
            'monitor_config' => ['port' => 22],
            'channels' => ['email', 'telegram'],
            'parent_id' => $contaboServer->id,
            'active' => true,
        ]);
    }
}
