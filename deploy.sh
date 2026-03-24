#!/bin/bash
# KNHstatus.hu — Deploy script
# Használat: ./deploy.sh deploy

set -euo pipefail

# Konfiguráció
REMOTE_USER="deploy"
REMOTE_HOST="knhstatus-deploy"
REMOTE_PATH="/var/www/knhstatus"
LOCAL_PATH="$(cd "$(dirname "$0")" && pwd)"
APP_URL="https://knhstatus.hu:8443"

# Színek
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# SSH parancs futtatás
remote_exec() {
    ssh "$REMOTE_HOST" "cd $REMOTE_PATH && $1"
}

# SSH parancs futtatás www-data userként (sudo)
remote_sudo_exec() {
    ssh "$REMOTE_HOST" "cd $REMOTE_PATH && sudo -u www-data $1"
}

deploy() {
    log_info "=== KNHstatus deploy indítása ==="

    # 1. Rsync — kód szinkronizálás (sudo rsync a szerveren, mert www-data a tulajdonos)
    log_info "Kód szinkronizálás (rsync)..."
    rsync -avz --no-perms --no-owner --no-group \
        --rsync-path="sudo rsync" \
        --delete \
        --exclude='.env' \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='storage/logs/*' \
        --exclude='storage/framework/cache/data/*' \
        --exclude='storage/framework/sessions/*' \
        --exclude='storage/framework/views/*' \
        --exclude='bootstrap/cache/*' \
        --exclude='passwords.env' \
        --exclude='CLAUDE.md' \
        --exclude='Dokumentumok' \
        --exclude='docs' \
        --exclude='.claude' \
        "$LOCAL_PATH/" "$REMOTE_HOST:$REMOTE_PATH/"

    # 2. Jogosultságok helyreállítása
    log_info "Jogosultságok beállítása..."
    ssh "$REMOTE_HOST" "sudo chown -R www-data:www-data $REMOTE_PATH"

    # 3. Composer install
    log_info "Composer install..."
    remote_sudo_exec "composer install --no-dev --optimize-autoloader --no-interaction 2>&1"

    # 4. Migráció
    log_info "Migrációk futtatása..."
    remote_sudo_exec "php artisan migrate --force 2>&1"

    # 5. Cache clear + rebuild
    log_info "Cache törlés és újraépítés..."
    remote_sudo_exec "php artisan config:cache 2>&1"
    remote_sudo_exec "php artisan route:cache 2>&1"
    remote_sudo_exec "php artisan view:clear 2>&1"

    # 5b. Filament + Livewire assets publish
    log_info "Filament + Livewire assets publish..."
    remote_sudo_exec "php artisan filament:assets 2>&1"

    # 6. Opcache reset
    log_info "Opcache reset..."
    remote_sudo_exec "php artisan tinker --execute=\"if (function_exists('opcache_reset')) { opcache_reset(); echo 'opcache reset OK'; } else { echo 'opcache not available'; }\" 2>&1" || true

    # 7. Storage link
    remote_sudo_exec "php artisan storage:link 2>&1" || true

    # 8. Health check
    log_info "=== Health check ==="
    health_check

    log_info "=== Deploy kész ==="
}

health_check() {
    local errors=0

    # HTTP válasz ellenőrzés
    log_info "HTTP ellenőrzés: $APP_URL/"
    local status
    status=$(curl -sk -o /dev/null -w "%{http_code}" "$APP_URL/" 2>/dev/null || echo "000")
    if [[ "$status" == "200" || "$status" == "302" ]]; then
        log_info "  Főoldal: HTTP $status"
    else
        log_error "  Főoldal: HTTP $status"
        ((errors++))
    fi

    log_info "HTTP ellenőrzés: $APP_URL/admin"
    status=$(curl -sk -o /dev/null -w "%{http_code}" "$APP_URL/admin" 2>/dev/null || echo "000")
    if [[ "$status" == "200" || "$status" == "302" ]]; then
        log_info "  Admin: HTTP $status"
    else
        log_error "  Admin: HTTP $status"
        ((errors++))
    fi

    # Route lista ellenőrzés
    log_info "Route lista ellenőrzés..."
    if remote_sudo_exec "php artisan route:list 2>&1" > /dev/null 2>&1; then
        log_info "  Route lista: OK"
    else
        log_error "  Route lista: HIBA"
        ((errors++))
    fi

    # Laravel log ellenőrzés
    log_info "Laravel log ellenőrzés..."
    local log_errors
    log_errors=$(remote_exec "grep -c 'ERROR' storage/logs/laravel.log 2>/dev/null || echo 0" 2>/dev/null || echo "0")

    if [[ $errors -gt 0 ]]; then
        log_error "=== $errors hiba! Ellenőrizd a fenti üzeneteket. ==="
        return 1
    else
        log_info "=== Minden health check OK ==="
        return 0
    fi
}

# Főprogram
case "${1:-help}" in
    deploy)
        # Timestamp marker a log ellenőrzéshez
        ssh "$REMOTE_HOST" "touch /tmp/deploy_marker" 2>/dev/null || true
        deploy
        ;;
    check)
        health_check
        ;;
    *)
        echo "Használat: $0 {deploy|check}"
        echo "  deploy  — Teljes deploy (rsync + composer + migrate + cache + health check)"
        echo "  check   — Csak health check futtatás"
        exit 1
        ;;
esac
