# Docker

Lokale dev-stack via `docker-compose.yml`. **De Laravel-app draait op de host** (`php artisan serve --port=8001` + `php artisan horizon`), niet in een container — Caddy proxyt ernaartoe via `host.docker.internal`.

## Services

| Service | Image | Host-poort → container | Doel |
|---------|-------|------------------------|------|
| `db` | `postgres:16-alpine` | `${DB_PORT:-5433}` → 5432 | Postgres (consumers/accounts/connections/audit + Cashier) |
| `redis` | `redis:7-alpine` (`--appendonly yes`) | `${REDIS_PORT:-6380}` → 6379 | Queue (default + webhooks), cache, sessions, Horizon |
| `caddy` | `caddy:2` | `${CADDY_PORT:-8090}` → 80 | Reverse-proxy → host `artisan serve` (`docker/Caddyfile`) |
| `adminer` | `adminer:4.8.1-standalone` | `127.0.0.1:${ADMINER_PORT:-8091}` → 8080 | DB-UI (alleen localhost; `pepa-linha-dark`) |

Volumes: `pgdata`, `redisdata`, `caddydata`, `caddyconfig`. Healthchecks op `db` (`pg_isready`) en `redis` (`redis-cli ping`).

## Gebruik

```bash
docker compose up -d                 # db + redis + caddy + adminer
php artisan serve --port=8001        # app op de host
php artisan horizon                  # queue-worker (2e terminal)
```

App-URL: `http://hub.emeq.test:8090` (vereist `127.0.0.1 hub.emeq.test` in `/etc/hosts`). Health: `GET /up` → `{"status":"up","database":"ok","redis":"ok"}`.

## Poort-conventie

Niet-default poorten (5433/6380/8090/8091) voorkomen botsing met een lokale Postgres/Redis op de standaardpoorten. Overschrijfbaar via `.env` (`DB_PORT`, `REDIS_PORT`, `CADDY_PORT`, `ADMINER_PORT`).
