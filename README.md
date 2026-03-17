# KNHstatus

**Independent 24/7 status monitoring server** for websites, APIs, SSL certificates, and infrastructure.

Built with Laravel, Filament admin panel, and a modular monitor architecture.

## Features

- **HTTP/HTTPS monitoring** — status codes, response times, keyword validation
- **SSL certificate expiry** — automatic daily checks and alerts
- **API endpoint monitoring** — JSON validation, Bearer token support
- **ICMP ping & TCP port checks** — server and service availability
- **Heartbeat / Dead man's switch** — cron job monitoring via POST endpoint
- **Smart alert grouping** — parent-child project hierarchy suppresses redundant alerts
- **Anomaly detection** — response time spike detection (3x 24h average)
- **Multi-channel notifications** — Email, Telegram, Viber, Webhook (Slack/Discord)
- **Quiet hours** — configurable notification suppression (02:00-06:00)
- **Admin dashboard** — Filament 3 with real-time status overview

## Tech Stack

- PHP 8.2 / Laravel / Filament 3
- MySQL 8.0
- Nginx + PHP-FPM
- Pest (testing framework)

## Quick Start

```bash
# Clone
git clone https://github.com/kalmarr/KNHStatus.git
cd KNHStatus

# Install
composer install
cp .env.example .env
php artisan key:generate

# Configure database in .env, then:
php artisan migrate
php artisan make:filament-user

# Run monitors
php artisan monitor:run
```

## Monitor Types

| Type | Class | Description |
|------|-------|-------------|
| `http` | HttpMonitor | HTTP/HTTPS status, response time, keyword check |
| `ssl` | SslMonitor | SSL certificate expiry monitoring |
| `api` | ApiMonitor | JSON endpoint validation with auth support |
| `ping` | PingMonitor | ICMP ping (requires fping) |
| `port` | PortMonitor | TCP port connectivity check |
| `heartbeat` | HeartbeatMonitor | Dead man's switch |

## Scheduled Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `monitor:run` | Every minute | Run all active monitors |
| `monitor:heartbeats` | Every 2 minutes | Check heartbeat timeouts |
| `monitor:aggregate-stats` | Daily 00:05 | Aggregate response time stats (p95/p99) |
| `monitor:ssl-report` | Daily 08:00 | SSL certificate expiry report |

## License

All rights reserved.

---

# Magyar

**Fuggetlen 24/7 statusz- es monitorozo szerver** weboldalak, API-k, SSL tanusitvanyok es infrastruktura figyelesehez.

Laravel alapu, Filament admin panellel es modularis monitor architekturval.

## Funkciok

- **HTTP/HTTPS monitorozas** — statusz kodok, valaszidok, kulcsszo validacio
- **SSL tanusitvany lejarat** — automatikus napi ellenorzes es riasztas
- **API vegpont monitorozas** — JSON validalas, Bearer token tamogatas
- **ICMP ping es TCP port** — szerver es szolgaltatas elerhetoseg
- **Heartbeat / Dead man's switch** — cron job figyeles POST endpoint-tal
- **Okos riasztas csoportositas** — szulo-gyermek projekt hierarchia, redundans riasztasok elnyomasa
- **Anomalia detektalas** — valaszido kiugrasok felismerese (3x 24 oras atlag)
- **Tobbcsatornas ertesites** — Email, Telegram, Viber, Webhook (Slack/Discord)
- **Csendes orak** — konfiguralhat ertesites szunet (02:00-06:00)
- **Admin felulet** — Filament 3 valos ideju statusz attekintegessel

## Gyors inditas

```bash
git clone https://github.com/kalmarr/KNHStatus.git
cd KNHStatus
composer install
cp .env.example .env
php artisan key:generate
# Adatbazis beallitasa a .env fajlban, majd:
php artisan migrate
php artisan make:filament-user
php artisan monitor:run
```
