<?php

namespace App\Services\Monitors;

use Illuminate\Support\Facades\Log;

/**
 * ICMP ping monitor using fping.
 *
 * Sends N ICMP packets to the target host using the fping utility.
 * The monitor is considered up if at least one packet was received back.
 * Average round-trip time is parsed from the fping summary output.
 *
 * The project URL may be a bare IP address or a hostname; the monitor
 * strips the scheme and path before invoking fping.
 *
 * Uses proc_open() with an argument array (no shell interpolation) to avoid
 * command injection vulnerabilities – equivalent to execFile() in Node.js.
 *
 * monitor_config keys used:
 *   count   (int) – number of ping packets (default: 3)
 *   timeout (int) – per-packet timeout in seconds, converted to ms for fping (default: 10)
 *
 * Result metadata includes:
 *   packets_sent      (int)
 *   packets_received  (int)
 *   packet_loss_pct   (int)
 *   avg_ms            (float|null)
 */
class PingMonitor extends BaseMonitor
{
    /**
     * Execute the ICMP ping check.
     *
     * Az fping kimenete a stderr-re kerül (-q kapcsolóval), ezért mind a stdout-ot,
     * mind a stderr-t olvassuk. A proc_open() argv tömbös formáját használjuk –
     * így shell-interpoláció nem történik, a hoszt paraméter nem értékelhető ki.
     *
     * @return CheckResult
     */
    public function run(): CheckResult
    {
        $host    = $this->resolveHost();
        $count   = (int) $this->config('count', 3);
        $timeout = $this->effectiveTimeout();

        // Az fping -t értéke milliszekundumban (1 s = 1000 ms)
        $argv = [
            'fping',
            '-c', (string) $count,
            '-q',
            '-t', (string) ($timeout * 1000),
            $host,
        ];

        // Argv tömbös proc_open: a shell NEM értelmezi a paramétereket –
        // ezzel elkerüljük az injection lehetőségét
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr (fping -q az összefoglalót ide írja)
        ];

        $process = @proc_open($argv, $descriptors, $pipes);

        if ($process === false) {
            Log::error('PingMonitor: proc_open failed – is fping installed?', [
                'project_id' => $this->project->id,
                'host'       => $host,
            ]);

            return CheckResult::down(
                errorMessage: 'Failed to start fping process. Is fping installed on the server?',
            );
        }

        fclose($pipes[0]);

        // fping -q az összefoglalót stderr-re írja
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        $outputStr = (string) $stderr;

        // Az fping összefoglaló sor formátuma:
        // host : xmt/rcv/%loss = X/Y/Z%, min/avg/max = A.B/C.D/E.F
        if (!$this->parseFpingOutput($outputStr, $sent, $received, $loss, $avgMs)) {
            Log::warning('PingMonitor: could not parse fping output', [
                'project_id' => $this->project->id,
                'host'       => $host,
                'output'     => $outputStr,
            ]);

            return CheckResult::down(
                errorMessage: 'fping output could not be parsed: ' . $outputStr,
            );
        }

        $metadata = [
            'packets_sent'     => $sent,
            'packets_received' => $received,
            'packet_loss_pct'  => $loss,
            'avg_ms'           => $avgMs,
        ];

        // Legalább egy visszaérkezett csomag = elérhető
        if ($received > 0) {
            return CheckResult::up(
                responseMs: $avgMs !== null ? (int) round($avgMs) : 0,
                metadata:   $metadata,
            );
        }

        return CheckResult::down(
            errorMessage: "Host unreachable – 100% packet loss ({$sent} packets sent)",
            metadata:     $metadata,
        );
    }

    /**
     * Parse the fping summary line for packet statistics and average latency.
     *
     * Az fping -q -c N kimenete:
     *   <host> : xmt/rcv/%loss = 3/2/33%, min/avg/max = 1.23/2.34/3.45
     * vagy ha 100% veszteség:
     *   <host> : xmt/rcv/%loss = 3/0/100%
     *
     * @param  string     $output    Raw fping output string.
     * @param  int|null   &$sent     Filled with packets sent.
     * @param  int|null   &$received Filled with packets received.
     * @param  int|null   &$loss     Filled with loss percentage.
     * @param  float|null &$avgMs    Filled with average RTT in ms, or null on 100% loss.
     * @return bool  True if parsing succeeded.
     */
    private function parseFpingOutput(
        string  $output,
        ?int    &$sent,
        ?int    &$received,
        ?int    &$loss,
        ?float  &$avgMs,
    ): bool {
        // A xmt/rcv/%loss részt keressük, az avg_ms opcionális (hiányzik 100% loss esetén)
        $matched = preg_match(
            '/xmt\/rcv\/%loss\s*=\s*(\d+)\/(\d+)\/(\d+)%(?:.*min\/avg\/max\s*=\s*[\d.]+\/([\d.]+)\/[\d.]+)?/i',
            $output,
            $m,
        );

        if (!$matched) {
            return false;
        }

        $sent     = (int) $m[1];
        $received = (int) $m[2];
        $loss     = (int) $m[3];
        $avgMs    = isset($m[4]) && $m[4] !== '' ? (float) $m[4] : null;

        return true;
    }

    /**
     * Strip URL scheme and path to extract a bare hostname or IP address.
     *
     * A projekt URL-je tartalmazhat sémát (http://, https://) – az fping csak
     * a hosztnevet vagy IP-t fogadja el argumentumként.
     */
    private function resolveHost(): string
    {
        $host = parse_url($this->project->url, PHP_URL_HOST);

        return $host ?? $this->project->url;
    }
}
