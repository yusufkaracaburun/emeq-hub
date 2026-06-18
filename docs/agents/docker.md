# Docker

**De hele stack draait in Docker** — app, queue-worker, Vite en de infra. De app-server is **FrankenPHP** (worker-mode via Laravel Octane); dev en prod draaien dezelfde runtime (parity), het verschil zit in code-levering (dev bind-mount + `watch`, prod immutable image).

Prod-deploy + release: zie [`../deployment.md`](../deployment.md).

## Services (dev — `docker-compose.yml`)

| Service | Image / target | Host-poort → container | Doel |
|---------|----------------|------------------------|------|
| `app` | `Dockerfile` target `dev` (FrankenPHP) | `${APP_PORT:-8092}` → 80 | App-server, worker-mode + `watch` (`docker/Caddyfile.dev`) |
| `worker` | idem `dev` | — | `php artisan queue:listen` (reload per job, node-vrij) |
| `vite` | `node:22-slim` | `5173` → 5173 | Vite dev-server (React HMR) |
| `db` | `postgres:16-alpine` | `${DB_PORT:-5433}` → 5432 | Postgres |
| `redis` | `redis:7-alpine` (`--appendonly yes`) | `${REDIS_PORT:-6380}` → 6379 | Queue + cache + sessions + Horizon |
| `pgadmin` | `dpage/pgadmin4` | `127.0.0.1:${PGADMIN_PORT:-8091}` → 80 | DB-UI (alleen localhost) |

Volumes: `pgdata`, `redisdata`, `pgadmindata` + een anonymous volume voor de Vite-`node_modules` (maskeert de host-darwin-binaries → container-linux-binaries).

## Gebruik

```bash
docker compose up -d --build         # hele stack
docker compose exec app php artisan migrate
docker compose exec app php artisan test --compact
docker compose logs -f app worker    # tail
docker compose down                  # stop (volumes blijven; -v wist data)
```

App-URL: `http://hub.emeq.test:8092` (vereist `127.0.0.1 hub.emeq.test` in `/etc/hosts`). Health: `GET /up` → `{"status":"up","database":"ok","redis":"ok"}`. Vite-HMR op `:5173`.

## Code-changes zien

- **PHP**: worker draait worker-mode; de `watch`-directive in `docker/Caddyfile.dev` herstart de worker bij een PHP-wijziging → na korte restart zichtbaar, geen rebuild.
- **React/CSS**: Vite-HMR, meteen zichtbaar.
- **Alleen rebuild bij**: nieuwe PHP-extensie of base-image-wijziging (`docker compose build app`).

## Netwerk-override

De host-`.env` heeft `DB_HOST=127.0.0.1` / `REDIS_HOST=127.0.0.1` (poorten 5433/6380) zodat host-tinker/tests werken. De `app`/`worker`-containers krijgen via compose `environment:` een override naar `db`/`redis` op de interne poorten 5432/6379. Eén bron, twee perspectieven.

## Poort-conventie

Niet-default poorten (5433/6380/8092/8091) voorkomen botsing met een lokale Postgres/Redis. Overschrijfbaar via `.env` (`DB_PORT`, `REDIS_PORT`, `APP_PORT`, `PGADMIN_PORT`).
