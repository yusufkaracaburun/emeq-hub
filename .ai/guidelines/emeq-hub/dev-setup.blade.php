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

# 3. Composer + migraties (SDK wordt automatisch vanaf GitHub gepakt)
composer install
php artisan migrate

# 4. (Optioneel) SDK clonen in packages/ voor referentie/grep — geen live-edit-link
mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 5. Laravel + Horizon op host
php artisan serve --port=8001
php artisan horizon  # in 2e terminal

# 6. SDK-changes: edit in de SDK-repo zelf, commit + push, daarna in de Hub:
#    composer update emeq/snelstart-api
```
@endverbatim

Open `http://hub.emeq.test:8090/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

## Veelgebruikte commando's

@verbatim
```bash
# DB
php artisan migrate
php artisan migrate:fresh --seed

# Tests — Hub (PHPUnit)
php artisan test --compact
php artisan test --compact --filter=ExampleTest

# Tests — SDK-package (Pest, eigen vendor)
cd packages/snelstart-api && ./vendor/bin/pest

# Format
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
routes/console.php   artisan-only commands (inspire)
routes/api.php       /v1/* — consumer-API (Bearer Sanctum + throttle:api)
routes/webhooks.php  /webhooks/{provider}/{...} + /cashier/webhook* — publiek, signature-verified
```
@endverbatim
