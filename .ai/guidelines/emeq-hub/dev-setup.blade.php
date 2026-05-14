## Lokale dev — eerste keer

@verbatim
```bash
# 0. Eenmalig: /etc/hosts toevoegen
echo "127.0.0.1 hub.emeq.test" | sudo tee -a /etc/hosts

# 1. .env van .env.example
cp .env.example .env
php artisan key:generate

# 2. Stack omhoog (postgres + redis + caddy)
docker compose up -d

# 3. SDK clonen in packages/ (voor live-edit; anders volstaat composer install vanaf VCS)
mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 4. Composer + migraties
composer install
php artisan migrate

# 5. Laravel + Horizon op host
php artisan serve --port=8001
php artisan horizon  # in 2e terminal
```
@endverbatim

Open `http://hub.emeq.test:8090/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

## Veelgebruikte commando's

@verbatim
```bash
# DB
php artisan migrate
php artisan migrate:fresh --seed

# Tests
./vendor/bin/pest --parallel
./vendor/bin/pint --dirty --format agent   # voor commit

# Horizon
php artisan horizon
php artisan horizon:status

# Routes
php artisan route:list --except-vendor

# Composer audit
composer audit                              # zie ignored advisories in composer.json
```
@endverbatim

## Routes

@verbatim
```
routes/web.php       smoke: GET /, GET /up
routes/api.php       /v1/* — consumer-API (Bearer Sanctum)
routes/webhooks.php  /webhooks/{provider} — inkomend van partners (no auth, signature-verified per provider)
```
@endverbatim
