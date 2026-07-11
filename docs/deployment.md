# Deployment & release — prod

Prod draait op een **eigen Ubuntu-server** via `docker-compose.prod.yml`, achter
**Cloudflare** via een **Cloudflare Tunnel**. Geen Laravel Cloud. Dev-stack: zie
[`agents/docker.md`](agents/docker.md).

## Architectuur in prod

```
internet ──TLS──▶ Cloudflare edge
                       │  versleutelde tunnel (uitgaand opgezet, geen open poort)
                       ▼
                  cloudflared ──http://app:80──┐
                                               │  docker compose -f docker-compose.prod.yml
                                               ├─ app        FrankenPHP worker-mode (Octane), :80
                                               ├─ horizon    php artisan horizon
                                               ├─ scheduler  php artisan schedule:work
                                               ├─ db         postgres:16 (named volume)
                                               └─ redis      redis:7 (named volume)
```

- **App** = immutable image (`Dockerfile` target `prod`): code + gebouwde assets
  gebakken, `composer install --no-dev`, productie-`php.ini`. Worker-mode **zonder**
  `watch` — code-changes landen pas bij een nieuwe image + container-herstart.
- **TLS** eindigt op de Cloudflare-rand. `cloudflared` bouwt een *uitgaande*
  verbinding, dus de server heeft **geen open poort 80/443** en het origin-IP blijft
  verborgen. Laravel vertrouwt de proxy via `trustProxies(at:'*')`
  (`bootstrap/app.php`) → https-detectie + echte client-IP.
- De app publiceert alleen op `127.0.0.1:8090` — loopback, puur voor de smoke-test
  op de server zelf.

## Cloudflare

### Bot Fight Mode moet UIT

Bot Fight Mode (free plan) gooit challenges naar clients die op bots lijken. Al het
partner-verkeer naar de Hub is server-to-server zonder browser: Exact-, Mollie- en
Snelstart-webhooks, plus consumer-calls op `/v1/*`. BFM blokkeert dat.

Hij is **niet per pad uit te zonderen**: BFM draait buiten de Ruleset Engine, waar
Skip/Bypass/Allow geen effect hebben. Uitzonderen kan alleen met Super Bot Fight Mode
(betaald). Op free is het dus alles-of-niets → **uit** op de `emeq.nl`-zone.

Dat kost je alleen BFM. DDoS-mitigatie, CDN, TLS, verborgen origin-IP, analytics,
WAF custom rules en rate limiting blijven gewoon werken op het free plan.

Vervang BFM door gerichte regels (free: 5 WAF custom rules, 1 rate-limiting rule):

| Doel | Regel |
|---|---|
| Webhooks vrij | `http.request.uri.path contains "/webhooks/"` → **Skip** (HMAC-signature is de poortwachter) |
| Admin afschermen | `http.request.uri.path contains "/admin"` → **Managed Challenge** (browser-verkeer; challenge doet geen pijn) |
| API afremmen | rate-limiting op `/v1/*` (Laravel heeft `throttle:api` als tweede laag) |

De echte authenticatie is en blijft Sanctum-PAT's + per-provider HMAC — niet een
bot-heuristiek.

### Tunnel aanmaken (eenmalig)

1. Zero-Trust-dashboard → **Networks → Tunnels → Create a tunnel** → type `Cloudflared`,
   naam `hub-emeq-prod`.
2. Kopieer het **connector-token** (begint met `ey…`) → `.env.prod` als
   `CLOUDFLARE_TUNNEL_TOKEN`. Dit is een secret.
3. **Public hostname** toevoegen: `hub.emeq.nl` → service `http://app:80`.
   Het DNS-record (`CNAME` naar de tunnel, proxied) wordt automatisch gezet — een
   bestaand A-record voor `hub.emeq.nl` moet eerst weg.

## Vereisten (eenmalig)

1. Server met Docker Engine + compose-plugin.
2. Cloudflare Tunnel aangemaakt (hierboven), Bot Fight Mode uit.
3. `.env.prod` op de server (van `.env.prod.example`), met ingevuld:
   - `APP_KEY` — `docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show`
   - `DB_PASSWORD`, `APP_URL=https://hub.emeq.nl`, `CLOUDFLARE_TUNNEL_TOKEN`
   - `.env.prod` is **gitignored** — nooit committen.
4. Partner-credentials (Exact) staan in de DB (spatie/laravel-settings), niet in
   env — beheer via admin → Beheer → Integratie-instellingen.

> **APP_KEY is de sleutel onder alle tokens.** Connection-tokens en `ExactSettings`
> zijn `encrypted` at rest. Een andere `APP_KEY` dan waarmee ze zijn geschreven maakt
> ze onleesbaar (`DecryptException`) en elke koppeling moet opnieuw. Bewaar 'm zoals
> je een DB-wachtwoord bewaart; roteren = alle connections opnieuw koppelen.

## Eerste deploy (schone start)

```bash
git clone <repo> emeq-hub && cd emeq-hub
git checkout master
cp .env.prod.example .env.prod        # invullen (APP_KEY, DB_PASSWORD, TUNNEL_TOKEN, …)

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml exec app \
  sh -c "php artisan migrate --force && php artisan optimize"
```

Smoke-test op de server: `curl -fsS http://127.0.0.1:8090/up` →
`{"status":"up","database":"ok","redis":"ok"}`, en `https://hub.emeq.nl/up` via
Cloudflare.

Daarna: admin-user aanmaken, inloggen op `/admin`, Exact-credentials invullen onder
Beheer → Integratie-instellingen, en de Exact-tenant koppelen.

## Release (terugkerend)

Lokaal werk landt op `master` (feature-/fix-branch → tests groen → ff-merge, zie
[`agents/workflow.md`](agents/workflow.md)). Daarna, op de server in de checkout:

```bash
make prod-deploy
```

Dat doet: `git pull --ff-only` → `pg_dump`-backup → image herbouwen → `migrate --force`
→ `optimize` → **`app` + `horizon` + `scheduler` herstarten** → health-check.

### Zonder git (rsync)

Draait de server niet vanuit git — bijvoorbeeld op een staging-box waar je snel wilt
itereren — push de working tree er dan lokaal heen en sla de pull-stap over:

```bash
make prod-rsync PROD_HOST=naschool   # lokaal; excludeert .git, vendor, node_modules, .env*
ssh naschool 'cd emeq-hub && make prod-up'
```

`prod-up` is `prod-deploy` zonder de `git pull`. Let op wat je hiermee inlevert: er is
geen commit-SHA die vertelt wát er draait, en de rollback uit § Rollback (checkout van
een eerdere SHA) werkt niet. Voor een echte prod-host is de git-route de juiste.

> **Waarom die restart**: worker-mode houdt code in geheugen. `up --build` vervangt de
> app-container, maar `horizon` en `scheduler` draaien door op de oude image tot een
> expliciete `restart`. Vergeten = queue draait oude code. `prod-deploy` doet het voor je.

### Volgorde-let-op

- **Migraties vóór code-activatie** is alleen veilig bij additieve migraties
  (forward-only, zie CLAUDE.md-invariant). Breaking schema-changes: deploy in twee
  stappen (expand → contract).

## Backup & restore

`make prod-deploy` maakt automatisch een dump vóór elke release. Handmatig:

```bash
make prod-backup            # → backups/emeq-hub-<timestamp>.sql.gz (gitignored)
```

Restore:

```bash
gunzip -c backups/emeq-hub-<timestamp>.sql.gz \
  | docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T db \
      psql -U emeq_hub -d emeq_hub
```

> `backups/` staat op de server-schijf. Zet er een off-site kopie naast (rsync/S3) —
> een dump op dezelfde disk als het `pgdata`-volume overleeft geen schijfstoring.

## Rollback

Images zijn immutable per commit. Terug naar de vorige werkende staat:

```bash
git checkout <vorige-commit-sha>
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml restart app horizon scheduler
```

Migratie-rollback is **niet** automatisch (forward-only in prod). Een kapotte migratie
vereist een nieuwe corrigerende migratie, geen `migrate:rollback` — vandaar de dump
vóór elke deploy.

## Smoke-test na elke release

```bash
curl -fsS https://hub.emeq.nl/up      # health via Cloudflare
make prod-ps                          # alles up/healthy
make prod-logs                        # app + horizon + tunnel
```

## Troubleshooting

| Symptoom | Oorzaak / fix |
|---|---|
| Partner-webhooks komen niet aan (403) | Bot Fight Mode staat aan op de zone — uitzetten (zie § Cloudflare) |
| `530` / `1033` van Cloudflare | tunnel-connector down: `make prod-logs`, check `CLOUDFLARE_TUNNEL_TOKEN` |
| `502` van de tunnel | `app` niet healthy — public hostname moet naar `http://app:80` wijzen |
| `500` op `/up` | db of redis onbereikbaar: `make prod-ps` + `make prod-logs` |
| `https` werkt niet / redirect-loop | check `APP_URL=https://…` en `SESSION_SECURE_COOKIE=true` |
| Code-change niet zichtbaar | `restart app horizon scheduler` vergeten — gebruik `make prod-deploy` |
| Queue draait oude code | idem |

## Niet committen

`.env.prod`, `backups/`, secrets, certs. Alleen `.env.prod.example` (template, geen
secrets) staat in git.
