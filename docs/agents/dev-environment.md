# Dev environment

Stack-agnostic — agents detect tooling from this repo and consult **official documentation** for commands.

## Detected in this repo

- Package manager: npm (`npm ci`)
- Frameworks: laravel
- Build script: `vite build`


## How to find commands

1. Check scripts in `package.json`, `composer.json`, Makefile, or project README
2. Respect the lockfile (`pnpm-lock.yaml` → pnpm, `yarn.lock` → yarn, `package-lock.json` → npm)
3. If unclear, look up the **official docs** for the detected framework/tooling (links below)
4. Do not assume a stack — use what this repo actually contains

## Docs lookup rule

When install, test, build, or deploy commands are unclear:

1. Read repo scripts and CI config first
2. Fetch or search the **official documentation** linked below (verify URL is current)
3. Note the docs version when relevant (e.g. Laravel 11 vs 12 from `composer.json`)
4. Cite the source in ADRs or comments for non-obvious architectural choices

## Official documentation

| Tool | Documentation |
| ---- | ------------- |
| laravel | https://laravel.com/docs/13.x |
| npm | https://docs.npmjs.com |

## Project-specific notes

### Lokale dev — eerste keer

```bash
# 0. Eenmalig: /etc/hosts toevoegen
echo "127.0.0.1 hub.emeq.test" | sudo tee -a /etc/hosts

# 1. .env van .env.example
cp .env.example .env
php artisan key:generate

# 2. Composer-deps op host (voor IDE/grep; container regenereert vendor zelf)
composer install

# 3. Hele stack omhoog in Docker (app + worker + vite + db + redis)
docker compose up -d --build

# 4. Migraties draaien in de app-container
docker compose exec app php artisan migrate

# 5. (Optioneel) SDK clonen in packages/ voor referentie/grep — geen live-edit-link
mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 6. SDK-changes: edit in de SDK-repo zelf, commit + push, daarna in de Hub:
#    composer update emeq/snelstart-api
```

Open `http://hub.emeq.test:8092/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

Dev draait in worker-mode met `watch`: PHP-changes zijn na een korte worker-restart zichtbaar (geen rebuild), React via Vite-HMR op `:5173`. Tests in de container: `docker compose exec app php artisan test --compact`.
