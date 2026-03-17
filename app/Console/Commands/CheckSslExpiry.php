<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Setting;
use App\Services\Monitors\SslMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Artisan command that runs SSL certificate checks on all SSL-type projects
 * and sends a consolidated summary email with expiry information.
 *
 * The report includes:
 * - Expired certificates (critical)
 * - Certificates expiring within 7 days (critical)
 * - Certificates expiring within 14 days (warning)
 * - Healthy certificates
 *
 * Intended to be scheduled once daily at 08:00 via Laravel Scheduler.
 *
 * Usage:
 *   php artisan monitor:ssl-report
 */
class CheckSslExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:ssl-report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run SSL certificate checks on all SSL projects and send a summary email';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Csak az 'ssl' típusú aktív projekteket ellenőrizzük
        $projects = Project::active()->where('type', 'ssl')->get();

        if ($projects->isEmpty()) {
            $this->info('No active SSL projects found.');

            return self::SUCCESS;
        }

        $this->info("Running SSL checks for {$projects->count()} project(s)...");

        $results = [];

        foreach ($projects as $project) {
            try {
                $monitor = new SslMonitor($project);
                $result  = $monitor->run();

                // A metaadatokból kiolvassuk a tanúsítvány információkat
                $results[] = [
                    'project'           => $project->name,
                    'url'               => $project->url,
                    'is_up'             => $result->isUp,
                    'days_until_expiry' => $result->metadata['days_until_expiry'] ?? null,
                    'expiry_date'       => $result->metadata['expiry_date']       ?? null,
                    'subject_cn'        => $result->metadata['subject_cn']        ?? null,
                    'severity'          => $result->metadata['severity']           ?? 'unknown',
                    'error'             => $result->errorMessage,
                ];

                $this->line(sprintf(
                    '  %s %-40s %s',
                    $result->isUp ? '[OK]' : '[FAIL]',
                    $project->name,
                    $result->metadata['severity'] ?? ($result->isUp ? 'ok' : 'error'),
                ));

            } catch (\Throwable $e) {
                Log::error('CheckSslExpiry: error checking project', [
                    'project_id' => $project->id,
                    'error'      => $e->getMessage(),
                ]);

                $results[] = [
                    'project'           => $project->name,
                    'url'               => $project->url,
                    'is_up'             => false,
                    'days_until_expiry' => null,
                    'expiry_date'       => null,
                    'subject_cn'        => null,
                    'severity'          => 'unknown',
                    'error'             => $e->getMessage(),
                ];
            }
        }

        $this->sendSummaryEmail($results);
        $this->info('SSL report sent.');

        return self::SUCCESS;
    }

    /**
     * Compose and send the SSL summary email.
     *
     * Az email a tanúsítványok lejáratát csoportosítva mutatja:
     * kritikus → warning → ok sorrendben.
     *
     * @param  array  $results  Array of per-project SSL check result arrays.
     */
    private function sendSummaryEmail(array $results): void
    {
        $recipient = Setting::get('alert_email', 'email');

        if (empty($recipient)) {
            Log::warning('CheckSslExpiry: alert_email not configured, skipping email');
            $this->warn('alert_email not configured in settings table – email skipped.');

            return;
        }

        // Eredmények rendezése súlyosság szerint
        usort($results, function (array $a, array $b): int {
            $order = ['expired' => 0, 'critical' => 1, 'warning' => 2, 'ok' => 3, 'unknown' => 4];

            return ($order[$a['severity']] ?? 99) <=> ($order[$b['severity']] ?? 99);
        });

        $body = $this->buildEmailBody($results);

        try {
            Mail::raw($body, function ($mail) use ($recipient): void {
                $mail->to($recipient)
                     ->subject('[KNHstatus] SSL tanúsítvány lejárat összefoglaló – ' . now()->toDateString());
            });
        } catch (\Throwable $e) {
            Log::error('CheckSslExpiry: failed to send summary email', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Failed to send summary email: ' . $e->getMessage());
        }
    }

    /**
     * Build the plain-text email body from the results array.
     *
     * @param  array  $results  Sorted array of per-project SSL check result arrays.
     * @return string
     */
    private function buildEmailBody(array $results): string
    {
        $lines   = [];
        $lines[] = 'KNHstatus.hu – SSL Tanúsítvány Lejárat Összefoglaló';
        $lines[] = 'Dátum: ' . now()->format('Y-m-d H:i:s');
        $lines[] = str_repeat('-', 60);
        $lines[] = '';

        foreach ($results as $r) {
            if (!$r['is_up']) {
                $lines[] = sprintf('❌ %s', $r['project']);
                $lines[] = sprintf('   URL: %s', $r['url']);
                $lines[] = sprintf('   Hiba: %s', $r['error'] ?? 'Ismeretlen hiba');
            } else {
                $icon = match ($r['severity']) {
                    'critical', 'expired' => '🔴',
                    'warning'             => '🟡',
                    default               => '🟢',
                };

                $lines[] = sprintf('%s %s', $icon, $r['project']);
                $lines[] = sprintf('   URL: %s', $r['url']);
                $lines[] = sprintf('   CN: %s', $r['subject_cn'] ?? 'n/a');
                $lines[] = sprintf('   Lejárat: %s (%d nap)', $r['expiry_date'] ?? 'n/a', $r['days_until_expiry'] ?? 0);
            }

            $lines[] = '';
        }

        $lines[] = str_repeat('-', 60);
        $lines[] = 'KNHstatus.hu monitorozó rendszer';

        return implode("\n", $lines);
    }
}
