#!/usr/bin/env bash
# Usage: bin/deploy.sh {dev|prod}

set -euo pipefail

MODE="${1:-}"
if [[ "$MODE" != "dev" && "$MODE" != "prod" ]]; then
    echo "Usage: $0 {dev|prod}"
    exit 1
fi

cd "$(git rev-parse --show-toplevel)"

log() { printf "\n\033[1;36m==>\033[0m [%s] %s\n" "$MODE" "$*"; }

SDK_DIR="packages/snelstart-api"
SDK_URL="https://github.com/yusufkaracaburun/emeq-snelstart-api.git"

log "git pull (ff-only)"
git pull --ff-only

log "snelstart-api SDK sync"
if [[ -d "$SDK_DIR/.git" ]]; then
    git -C "$SDK_DIR" pull --ff-only
else
    git clone "$SDK_URL" "$SDK_DIR"
fi

if [[ "$MODE" == "dev" ]]; then
    log "composer install"
    composer install

    log "migraties"
    php artisan migrate

    log "cache clear"
    php artisan optimize:clear

    log "queue restart"
    php artisan queue:restart

    log "dev klaar — start Horizon met: php artisan horizon"
    exit 0
fi

log "composer install (no-dev, optimized)"
composer install --no-dev --optimize-autoloader

log "migraties (--force)"
php artisan migrate --force

log "config + route + view + event cache"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log "queue restart"
php artisan queue:restart

log "horizon terminate"
php artisan horizon:terminate || true

log "prod klaar"
