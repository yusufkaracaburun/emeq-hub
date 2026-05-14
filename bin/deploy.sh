#!/usr/bin/env bash
# bin/deploy.sh — deploy emeq-hub naar dev of prod
#
# Usage:
#   bin/deploy.sh dev              lokale dev sync (latest SDK-master via composer hook)
#   bin/deploy.sh prod              productie deploy (SDK-SHA pinned uit .gitmodules)
#   bin/deploy.sh prod --unpin      productie deploy maar pull tóch latest SDK-master
#
# Aannames:
#   - Wordt vanuit de repo-root of een subdir gedraaid (cd naar root via git)
#   - composer + php artisan in PATH
#   - prod-host heeft schrijfrechten in storage/ en bootstrap/cache/
#   - Voor Laravel Cloud: zie .docs/stack/deploy-laravel-cloud.md (niet dit script)

set -euo pipefail

MODE="${1:-}"
FLAG="${2:-}"

if [[ "$MODE" != "dev" && "$MODE" != "prod" ]]; then
    echo "Usage: $0 {dev|prod} [--unpin]"
    exit 1
fi

cd "$(git rev-parse --show-toplevel)"

log() { printf "\n\033[1;36m==>\033[0m [%s] %s\n" "$MODE" "$*"; }

log "git pull (ff-only)"
git pull --ff-only

if [[ "$MODE" == "dev" ]]; then
    log "composer install — submodule sync naar latest master via post-install-cmd hook"
    composer install

    log "migraties"
    php artisan migrate

    log "cache clear"
    php artisan optimize:clear

    log "queue restart (Horizon supervisor pakt 't op zodra die draait)"
    php artisan queue:restart

    log "dev klaar — start Horizon handmatig met: php artisan horizon"
    exit 0
fi

# ===== prod =====

if [[ "$FLAG" == "--unpin" ]]; then
    log "submodule update --remote (BLEEDING EDGE: pullt SDK-master ipv pinned SHA)"
    git submodule update --init --recursive --remote --merge
else
    log "submodule update (pinned SHA uit Hub-tree)"
    git submodule update --init --recursive
fi

log "composer install (no-dev, optimized, no-scripts — hook overrulet anders de pin)"
composer install --no-dev --optimize-autoloader --no-scripts

log "Laravel discover (handmatig — anders gemist door --no-scripts)"
php artisan package:discover --ansi

log "migraties (--force)"
php artisan migrate --force

log "config + route + view + event cache"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log "queue restart"
php artisan queue:restart

log "horizon terminate — supervisor moet 'm opnieuw starten"
php artisan horizon:terminate || true

log "prod klaar"
