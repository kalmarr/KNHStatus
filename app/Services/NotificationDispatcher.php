<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notification dispatcher for monitoring alerts.
 *
 * Sends alert messages via Email, Telegram, Viber, and Webhook channels.
 * Each channel is only used if the project's channels array includes it.
 *
 * Quiet hours (02:00–06:00 server time): only email is sent.
 * Telegram and Viber are suppressed during this window to avoid waking people.
 *
 * Channel configuration is read from the settings table:
 *   group=email    : alert_email
 *   group=telegram : telegram_bot_token, telegram_chat_id
 *   group=viber    : viber_auth_token, viber_receiver
 *   group=webhook  : webhook_url
 *
 * Every dispatched alert (successful or failed) creates an Alert record
 * in the database for audit purposes.
 */
class NotificationDispatcher
{
    // Csendes órák: 02:00–06:00 között csak email megy ki
    private const QUIET_HOUR_START = 2;
    private const QUIET_HOUR_END   = 6;

    public function __construct(
        private readonly Client $http = new Client(['timeout' => 10]),
    ) {}

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Dispatch an incident-open alert to all configured channels.
     *
     * Ezt a metódust az incidens megnyitásakor hívja a MonitorService.
     *
     * @param  Project   $project   The project that went down or triggered an anomaly.
     * @param  Incident  $incident  The newly opened incident.
     */
    public function sendDownAlert(Project $project, Incident $incident): void
    {
        $message = $this->buildDownMessage($project, $incident);
        $this->dispatch($project, $incident, $message);
    }

    /**
     * Dispatch an incident-resolved alert to all configured channels.
     *
     * Ezt a metódust a MonitorService hívja, amikor a projekt visszaáll.
     * Recovery riasztás esetén is érvényesül a quiet hours szabály.
     *
     * @param  Project   $project   The project that recovered.
     * @param  Incident  $incident  The now-resolved incident.
     */
    public function sendRecoveryAlert(Project $project, Incident $incident): void
    {
        $message = $this->buildRecoveryMessage($project, $incident);
        $this->dispatch($project, $incident, $message);
    }

    /**
     * Dispatch an anomaly alert — email only, regardless of channels config.
     *
     * Az anomália warning természetéből adódóan nem zavarjuk a Telegram/Viber
     * csatornákat – az email elégséges.
     *
     * @param  Project   $project   The project with anomalous response time.
     * @param  Incident  $incident  The anomaly incident.
     */
    public function sendAnomalyAlert(Project $project, Incident $incident): void
    {
        $message = $this->buildAnomalyMessage($project, $incident);

        // Anomáliánál mindig csak email csatornán küldünk
        $this->sendEmail($project, $incident, $message);
    }

    /**
     * Send a test message to a specific channel (used from tinker / CLI).
     *
     * Hasznos a csatorna konfiguráció teszteléséhez anélkül, hogy éles
     * incidenst kellene létrehozni.
     *
     * @param  string  $channel  One of: email, telegram, viber, webhook.
     */
    public function testSend(string $channel): void
    {
        $message = '[TEST] KNHstatus.hu – csatorna teszt üzenet | ' . now()->toDateTimeString();

        match ($channel) {
            'email'    => $this->sendRawEmail($message),
            'telegram' => $this->sendTelegram(null, null, $message),
            'viber'    => $this->sendViber(null, null, $message),
            'webhook'  => $this->sendWebhook(null, null, $message),
            default    => throw new \InvalidArgumentException("Unknown channel: {$channel}"),
        };
    }

    // -------------------------------------------------------------------------
    // Channel dispatching
    // -------------------------------------------------------------------------

    /**
     * Dispatch to all channels listed in the project's channels array.
     *
     * @param  Project       $project
     * @param  Incident      $incident
     * @param  string        $message
     */
    private function dispatch(Project $project, Incident $incident, string $message): void
    {
        $channels = (array) ($project->channels ?? ['email']);

        foreach ($channels as $channel) {
            match ($channel) {
                'email'    => $this->sendEmail($project, $incident, $message),
                'telegram' => $this->sendTelegramIfAllowed($project, $incident, $message),
                'viber'    => $this->sendViberIfAllowed($project, $incident, $message),
                'webhook'  => $this->sendWebhook($project, $incident, $message),
                default    => Log::warning("NotificationDispatcher: unknown channel '{$channel}'", [
                    'project_id' => $project->id,
                ]),
            };
        }
    }

    // -------------------------------------------------------------------------
    // Email
    // -------------------------------------------------------------------------

    /**
     * Send an alert via email using Laravel's Mail facade.
     *
     * @param  Project   $project
     * @param  Incident  $incident
     * @param  string    $message
     */
    private function sendEmail(Project $project, Incident $incident, string $message): void
    {
        $recipient = Setting::get('alert_email', 'email');

        if (empty($recipient)) {
            Log::warning('NotificationDispatcher: alert_email not configured', [
                'project_id' => $project->id,
            ]);

            $this->recordAlert($project, $incident, 'email', 'failed', $message, 'alert_email setting is not configured');

            return;
        }

        try {
            Mail::raw($message, function ($mail) use ($recipient, $project): void {
                $mail->to($recipient)
                     ->subject('[KNHstatus] ' . $project->name);
            });

            $this->recordAlert($project, $incident, 'email', 'sent', $message);

        } catch (\Throwable $e) {
            Log::error('NotificationDispatcher: email send failed', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);

            $this->recordAlert($project, $incident, 'email', 'failed', $message, $e->getMessage());
        }
    }

    /**
     * Send a raw email without an associated project/incident (for test sends).
     */
    private function sendRawEmail(string $message): void
    {
        $recipient = Setting::get('alert_email', 'email');

        if (empty($recipient)) {
            throw new \RuntimeException('alert_email not configured in settings table');
        }

        Mail::raw($message, function ($mail) use ($recipient): void {
            $mail->to($recipient)->subject('[KNHstatus] Test');
        });
    }

    // -------------------------------------------------------------------------
    // Telegram
    // -------------------------------------------------------------------------

    /**
     * Send Telegram message only outside quiet hours.
     *
     * Éjjel 02:00–06:00 között elnyomjuk a Telegram értesítést,
     * hogy ne keltsük fel az operátort nem kritikus esetekben.
     *
     * @param  Project   $project
     * @param  Incident  $incident
     * @param  string    $message
     */
    private function sendTelegramIfAllowed(Project $project, Incident $incident, string $message): void
    {
        if ($this->isQuietHours()) {
            // Csendes óra – Telegram értesítés elnyomva, naplózzuk
            Log::info('NotificationDispatcher: Telegram suppressed (quiet hours)', [
                'project_id' => $project->id,
            ]);

            $this->recordAlert($project, $incident, 'telegram', 'skipped', $message, 'Quiet hours (02:00–06:00)');

            return;
        }

        $this->sendTelegram($project, $incident, $message);
    }

    /**
     * Perform the Telegram Bot API call.
     *
     * @param  Project|null   $project
     * @param  Incident|null  $incident
     * @param  string         $message
     */
    private function sendTelegram(?Project $project, ?Incident $incident, string $message): void
    {
        $token  = Setting::get('telegram_bot_token', 'telegram');
        $chatId = Setting::get('telegram_chat_id', 'telegram');

        if (empty($token) || empty($chatId)) {
            Log::warning('NotificationDispatcher: Telegram not configured');

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'telegram', 'failed', $message, 'Telegram bot token or chat_id not configured');
            }

            return;
        }

        try {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";

            $this->http->post($url, [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'HTML',
                ],
            ]);

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'telegram', 'sent', $message);
            }

        } catch (GuzzleException $e) {
            Log::error('NotificationDispatcher: Telegram send failed', [
                'project_id' => $project?->id,
                'error'      => $e->getMessage(),
            ]);

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'telegram', 'failed', $message, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Viber
    // -------------------------------------------------------------------------

    /**
     * Send Viber message only outside quiet hours.
     *
     * @param  Project   $project
     * @param  Incident  $incident
     * @param  string    $message
     */
    private function sendViberIfAllowed(Project $project, Incident $incident, string $message): void
    {
        if ($this->isQuietHours()) {
            Log::info('NotificationDispatcher: Viber suppressed (quiet hours)', [
                'project_id' => $project->id,
            ]);

            $this->recordAlert($project, $incident, 'viber', 'skipped', $message, 'Quiet hours (02:00–06:00)');

            return;
        }

        $this->sendViber($project, $incident, $message);
    }

    /**
     * Placeholder for Viber Bot API integration.
     *
     * TODO: Viber Business Messages API integrációja a következő fázisban.
     * A Viber REST API dokumentáció: https://developers.viber.com/docs/api/rest-bot-api/
     *
     * @param  Project|null   $project
     * @param  Incident|null  $incident
     * @param  string         $message
     */
    private function sendViber(?Project $project, ?Incident $incident, string $message): void
    {
        // TODO: Viber API integráció – következő fejlesztési fázisban
        Log::info('NotificationDispatcher: Viber channel is not yet implemented', [
            'project_id' => $project?->id,
        ]);

        if ($project && $incident) {
            $this->recordAlert($project, $incident, 'viber', 'skipped', $message, 'Viber not yet implemented');
        }
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * Send a JSON payload to the configured webhook URL (Slack, Discord, custom).
     *
     * A webhook nincs quiet hours hatálya alatt – mindig küld, ha konfigurálva van.
     *
     * @param  Project|null   $project
     * @param  Incident|null  $incident
     * @param  string         $message
     */
    private function sendWebhook(?Project $project, ?Incident $incident, string $message): void
    {
        $webhookUrl = Setting::get('webhook_url', 'webhook');

        if (empty($webhookUrl)) {
            Log::warning('NotificationDispatcher: webhook_url not configured');

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'webhook', 'failed', $message, 'webhook_url not configured');
            }

            return;
        }

        try {
            // Slack/Discord kompatibilis payload – egyéb rendszerek is elfogadják
            $this->http->post($webhookUrl, [
                'json' => [
                    'text'       => $message,
                    'project_id' => $project?->id,
                    'project'    => $project?->name,
                    'incident'   => $incident?->id,
                    'timestamp'  => now()->toIso8601String(),
                ],
            ]);

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'webhook', 'sent', $message);
            }

        } catch (GuzzleException $e) {
            Log::error('NotificationDispatcher: webhook send failed', [
                'project_id' => $project?->id,
                'url'        => $webhookUrl,
                'error'      => $e->getMessage(),
            ]);

            if ($project && $incident) {
                $this->recordAlert($project, $incident, 'webhook', 'failed', $message, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Alert record
    // -------------------------------------------------------------------------

    /**
     * Persist an Alert record for audit and retry purposes.
     *
     * @param  Project        $project
     * @param  Incident       $incident
     * @param  string         $channel   One of: email, telegram, viber, webhook.
     * @param  string         $status    One of: sent, failed, skipped.
     * @param  string         $message   The rendered message body.
     * @param  string|null    $error     Error detail if status is 'failed' or 'skipped'.
     */
    private function recordAlert(
        Project  $project,
        Incident $incident,
        string   $channel,
        string   $status,
        string   $message,
        ?string  $error = null,
    ): void {
        Alert::create([
            'project_id'  => $project->id,
            'incident_id' => $incident->id,
            'channel'     => $channel,
            'status'      => $status,
            'message'     => $message,
            'error'       => $error,
            'sent_at'     => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Message builders
    // -------------------------------------------------------------------------

    /**
     * Build the down/outage alert message body.
     */
    private function buildDownMessage(Project $project, Incident $incident): string
    {
        return sprintf(
            "🔴 LEÁLLÁS: %s\n\nURL: %s\nIncidens: %s\nSúlyosság: %s\nIdeje: %s\n\nLeírás: %s",
            $project->name,
            $project->url,
            $incident->title,
            strtoupper($incident->severity),
            $incident->started_at->format('Y-m-d H:i:s'),
            $incident->description ?? 'Nincs leírás',
        );
    }

    /**
     * Build the recovery alert message body.
     */
    private function buildRecoveryMessage(Project $project, Incident $incident): string
    {
        $duration = $incident->durationMinutes();

        return sprintf(
            "✅ HELYREÁLLT: %s\n\nURL: %s\nLeállás időtartama: %d perc\nMegoldva: %s",
            $project->name,
            $project->url,
            $duration ?? 0,
            $incident->resolved_at?->format('Y-m-d H:i:s') ?? 'most',
        );
    }

    /**
     * Build the anomaly alert message body.
     */
    private function buildAnomalyMessage(Project $project, Incident $incident): string
    {
        return sprintf(
            "⚠️ ANOMÁLIA: %s\n\nURL: %s\nLeírás: %s\nIdeje: %s",
            $project->name,
            $project->url,
            $incident->description ?? 'Szokatlanul magas válaszidő',
            $incident->started_at->format('Y-m-d H:i:s'),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the current server time falls within quiet hours (02:00–06:00).
     *
     * A szerveroldali időzóna alapján dönti el, hogy csendes-e az óra.
     * Ez az app.timezone config értékétől függ – Budapest esetén CET/CEST.
     */
    private function isQuietHours(): bool
    {
        $currentHour = (int) now()->format('G');

        return $currentHour >= self::QUIET_HOUR_START && $currentHour < self::QUIET_HOUR_END;
    }
}
