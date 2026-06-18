# Deployment & release — prod

Prod draait op een eigen server (Ubuntu) via `docker-compose.prod.yml`, achter
**Cloudflare** voor TLS. Geen Laravel Cloud. Dev-stack: zie
[`agents/docker.md`](agents/docker.md).

## Architectuur in prod

```
Cloudflare (TLS-terminatie)  ──HTTP:80──▶  server
                                              │  docker compose -f docker-compose.prod.yml
                                              ├─ app      FrankenPHP worker-mode (Octane), :80
                                              ├─ horizon  php artisan horizon
                                              ├─ db       postgres:16 (named volume)
                                              └─ redis    redis:7 (named volume)
```

- **App** = immutable image (`Dockerfile` target `prod`): code + gebouwde assets
  gebakken, `composer install --no-dev`, productie-`php.ini`. Worker-mode **zonder**
  `watch` — code-changes landen pas bij een nieuwe image + container-herstart.
- **TLS** via Cloudflare; origin serveert plain HTTP op `:80`
  (`docker/Caddyfile`, `auto_https off`). Laravel vertrouwt CF-proxies via
  `trustProxies(at:'*')` (`bootstrap/app.php`) → https-detectie + echte client-IP.

## Vereisten (eenmalig)

1. Server met Docker Engine + compose-plugin.
2. DNS van `hub.emeq.nl` in Cloudflare, proxied (oranje wolk) → server-IP.
3. `.env.prod` op de server (van `.env.prod.example`), met ingevuld:
   - `APP_KEY` — `docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show`
   - `DB_PASSWORD`, `APP_URL=https://hub.emeq.nl`
   - `.env.prod` is **gitignored** — nooit committen.
4. Partner-credentials (Exact) staan in de DB (spatie/laravel-settings), niet in
   env — beheer via admin → Beheer → Integratie-instellingen.

### Cloudflare

- **SSL/TLS-mode**: origin praat HTTP, dus:
  - **Cloudflare Tunnel** (`cloudflared`) — aanbevolen: CF↔origin versleuteld
    zónder origin-cert, geen open poort. Equivalent van Full (strict).
  - **Proxy + Flexible** — werkt, maar CF↔origin is plaintext (minder veilig).
  - **Full (strict) zónder Tunnel** vereist een origin-cert → dan niet HTTP-only.
- **Firewall**: als je géén Tunnel gebruikt, beperk poort 80 tot de
  [Cloudflare-IP-ranges](https://www.cloudflare.com/ips/). Anders is
  `trustProxies('*')` spoofbaar (een directe request kan `X-Forwarded-Proto`
  vervalsen).

## Eerste deploy

```bash
git clone <repo> emeq-hub && cd emeq-hub
git checkout master
cp .env.prod.example .env.prod        # invullen (APP_KEY, DB_PASSWORD, …)

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml exec app \
  sh -c "php artisan migrate --force && php artisan optimize"
```

Smoke-test: `curl -H 'Host: hub.emeq.nl' http://localhost/up` op de server →
`{"status":"up","database":"ok","redis":"ok"}`, en `https://hub.emeq.nl/up` via
Cloudflare.

## Release (terugkerend)

Lokaal werk landt op `master` (feature-/fix-branch → tests groen → ff-merge,
zie [`agents/workflow.md`](agents/workflow.md)). Daarna op de server:

```bash
# 1. Nieuwe code halen
git fetch origin && git checkout master && git pull --ff-only

# 2. Image opnieuw bouwen (assets + composer --no-dev gebakken) en herstarten
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build

# 3. Migraties + caches verversen
docker compose -f docker-compose.prod.yml exec app \
  sh -c "php artisan migrate --force && php artisan optimize"

# 4. Worker-code activeren (Octane + Horizon draaien oude code tot herstart)
docker compose -f docker-compose.prod.yml restart app horizon
```

> **Waarom stap 4**: worker-mode houdt code in geheugen. `up --build` vervangt de
> app-container (nieuwe code), maar `horizon` draait door op de oude image tot een
> expliciete `restart`. Altijd `app` + `horizon` samen herstarten na een release.

### Volgorde-let-op
- **Migraties vóór code-activatie** alleen veilig bij additieve migraties
  (forward-only, zie CLAUDE.md-invariant). Breaking schema-changes: deploy in twee
  stappen (expand → contract).
- Geen `down` nodig voor een release — `up --build` doet een rolling recreate.

## Rollback

Images zijn immutable per commit. Terug naar de vorige werkende staat:

```bash
git checkout <vorige-commit-sha>
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml restart app horizon
```

Migratie-rollback is **niet** automatisch (forward-only in prod). Een kapotte
migratie vereist een nieuwe corrigerende migratie, geen `migrate:rollback`.

## Smoke-test na elke release

```bash
curl -fsS https://hub.emeq.nl/up                       # health
docker compose -f docker-compose.prod.yml ps           # alles up/healthy
docker compose -f docker-compose.prod.yml logs --since 2m app horizon
```

## Troubleshooting

| Symptoom | Oorzaak / fix |
|---|---|
| `https` werkt niet / redirect-loop | CF SSL-mode + `trustProxies`; check `APP_URL=https://…` en `SESSION_SECURE_COOKIE=true` |
| 521/522 in Cloudflare | origin `:80` niet bereikbaar — container down of firewall blokkeert CF |
| Code-change niet zichtbaar | `restart app horizon` vergeten na `up --build` |
| Queue draait oude code | idem — `horizon` herstarten |
| `502` op `/up` | app-container niet healthy: `logs app` |

## Niet committen
`.env.prod`, secrets, certs. Alleen `.env.prod.example` (template, geen secrets)
staat in git.
