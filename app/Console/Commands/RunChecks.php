<?php

namespace App\Console\Commands;

use App\Services\MonitorService;
use Illuminate\Console\Command;

/**
 * Artisan command that triggers all active monitoring checks.
 *
 * Intended to be scheduled every minute via Laravel Scheduler.
 * Optionally targets a single project with the --project option,
 * which is useful for ad-hoc testing and debugging without affecting
 * other projects.
 *
 * Usage:
 *   php artisan monitor:run              # Check all active projects
 *   php artisan monitor:run --project=5  # Check only project ID 5
 */
class RunChecks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:run
        {--project= : Optional project ID to check a single project}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run monitoring checks for all active projects (or a single project with --project=ID)';

    /**
     * Execute the console command.
     *
     * @param  MonitorService  $service
     */
    public function handle(MonitorService $service): int
    {
        $projectId = $this->option('project');

        if ($projectId !== null) {
            // Egyetlen projekt ellenőrzése – fejlesztéshez és debuggoláshoz
            $this->info("Running check for project ID: {$projectId}");

            try {
                $service->checkSingle((int) $projectId);
                $this->info('Check completed successfully.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->error("Project ID {$projectId} not found.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // Minden aktív projekt ellenőrzése a scheduler futtatásakor
        $this->info('Running checks for all active projects...');
        $service->checkAll();
        $this->info('All checks completed.');

        return self::SUCCESS;
    }
}
