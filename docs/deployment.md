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

De tunnel is **locally-managed**: de ingress staat in `docker/cloudflared.yml`, in git —
niet token/dashboard-managed, want dan leeft de routering in een dashboard, onzichtbaar
bij review.

```bash
cloudflared tunnel login                                    # eenmalig, schrijft cert.pem
cloudflared tunnel create emeq-hub-prod                     # → ~/.cloudflared/<uuid>.json
cloudflared --config /dev/null tunnel route dns --overwrite-dns <uuid> hub.emeq.nl
```

Zet het uuid uit stap 2 in `docker/cloudflared.yml` (`tunnel:`) en breng het
credentials-bestand naar de server — dat is een secret, `.cloudflared/` is gitignored:

```bash
cat ~/.cloudflared/<uuid>.json \
  | ssh hub 'sudo -u deploy tee /home/deploy/emeq-hub/.cloudflared/credentials.json >/dev/null'
ssh hub 'sudo chown 65532:65532 /home/deploy/emeq-hub/.cloudflared/credentials.json'
```

> **Die `chown` is niet optioneel.** De `cloudflare/cloudflared`-image draait als uid
> **65532** (nonroot) en kan een `0600`-bestand van `deploy` niet lezen. Gevolg: een
> restart-loop met `couldn't read tunnel credentials: permission denied`. Eigenaar 65532
> + mode 0600 houdt het secret dicht én leesbaar voor de connector.

> **`--config /dev/null` evenmin.** Bestaat er een `~/.cloudflared/config.yml` (die van
> de dev-tunnel), dan laat `tunnel route dns` díé config vóórgaan op het naam-argument
> en routeert je hostname naar de **verkeerde tunnel** — mét een succesmelding. Geef het
> uuid expliciet mee en zet de config opzij.

## Server-provisioning (eenmalig)

Prod draait op een **OVH VPS-3** (6 vCore / 12 GB / 100 GB NVMe) in **Duitsland –
Limburg**, **Ubuntu 26.04 LTS** (support tot 2031). EU-regio en een ISO 27001-
gecertificeerd datacenter zijn hier geen detail: het Exact Data & Security-formulier
vraagt expliciet naar dataopslag-regio, logische toegang en fysieke toegang.

De host draait alleen Docker, ufw, fail2ban en unattended-upgrades — de
applicatie-runtime (PHP 8.4, FrankenPHP, Postgres, Redis) zit in containers en raakt de
distro niet. Vandaar de langste LTS: deze machine bewaart de `APP_KEY` en die wil je zo
min mogelijk verhuizen.

Een kale server komt met één commando in de juiste staat. Wélk commando hangt af van
het image — dat bepaalt of je überhaupt als root naar binnen komt:

```bash
# OVH's Ubuntu (geen root-login; je logt in als `ubuntu` met sudo)
ssh-copy-id ubuntu@<ip>                                # sleutel erop, vóór alles
ssh ubuntu@<ip> 'sudo bash -s' < bin/provision-vps.sh

# Image mét root-login
ssh-copy-id root@<ip>
ssh root@<ip> 'bash -s' < bin/provision-vps.sh
```

Het script zoekt de sleutel bij de gebruiker die het aanroept (`$SUDO_USER`) en valt
terug op `/root`. Zit er daar geen sleutel, dan **weigert het te draaien** — het zet
wachtwoord-login uit, en zonder werkende sleutel is de enige weg terug OVH's
rescue-mode.

Dat doet: apt-upgrade, `unattended-upgrades` (security-patches), een 4 GB **swapfile**
(`vm.swappiness=10`, vangnet tegen OOM), Docker Engine + compose-plugin met log-rotatie,
een `deploy`-user in de `docker`-groep, de repo clonen naar `/home/deploy/emeq-hub`, `ufw`
(**alles dicht behalve SSH**, `limit` = rate-limited tegen brute-force), `fail2ban`, een
**dagelijkse backup-timer** (systemd, 04:00), en tot slot SSH-hardening (key-only, geen
root-login, geen wachtwoord). Het sluit af met een verificatie-blok: het **assert** de
effectieve sshd-staat (`sshd -T`, niet alleen "het bestand is geschreven" — zie hieronder)
en drukt docker-versie, `ufw status`, swap, de backup-timer, de fail2ban-jail en de
repo-SHA af.

### Sluit je sessie niet voordat je `deploy` hebt getest

Het script zet root-login en wachtwoord-login uit. Klopt er iets niet aan de
sleutel-setup van de `deploy`-user, dan merk je dat pas als je opnieuw wilt inloggen —
en dan is het te laat. Dus, **met de huidige sessie nog open**, in een tweede terminal:

```bash
ssh deploy@<ip> 'whoami && docker ps && sudo -n systemctl --version >/dev/null && echo OK'
```

Dit test de drie dingen die `deploy` nodig heeft: inloggen met de sleutel, bij de
Docker-socket kunnen (groepslidmaatschap) en de sudoers-regel. Let op: `sudo -n true`
werkt hier **niet** — de regel staat alleen `systemctl` en `apt-get` toe.

- Krijg je `deploy` … `OK` → hardening is goed, de sessie mag dicht.
- Krijg je `Permission denied` → **laat de sessie open** en herstel:

  ```bash
  sudo rm /etc/ssh/sshd_config.d/00-emeq-hardening.conf
  sudo systemctl restart ssh.socket || sudo systemctl reload ssh.service
  ```

  De `sudo` is er niet voor de sier: op de OVH-route ben je in die sessie `ubuntu`,
  niet root.

Werkt beide niet meer, dan rest alleen OVH's rescue-mode (KVM-console in het
OVH-paneel). Die tweede terminal kost tien seconden.

> **Welk account is je vangnet?** Op de OVH-route blijft `ubuntu` bestaan met zijn
> sleutel — dat is de weg terug én de enige manier om het script later opnieuw te
> draaien (`deploy` heeft geen volledige sudo). Op een image mét root-login is root
> ná de hardening niet meer bereikbaar via SSH: daar is er geen tweede weg naar binnen
> behalve de KVM-console.

### De drop-in heet `00-`, niet `99-`

OpenSSH hanteert **first-obtained-value-wins** en leest `/etc/ssh/sshd_config.d/*.conf`
alfabetisch. OVH's image levert `50-cloud-init.conf` met `PasswordAuthentication yes`.
Een hardening-bestand dat dáárna komt (`99-…`) wordt genegeerd: `sshd -t` slaagt, het
script meldt succes, en wachtwoord-login staat gewoon nog aan. Vandaar
`00-emeq-hardening.conf` — en vandaar dat het script na de reload aan `sshd -T` vraagt
wat er *effectief* geldt en afbreekt als dat niet klopt. Een drop-in wegschrijven is
geen bewijs dat sshd 'm honoreert.

**Poort 80/443 blijven dicht.** `cloudflared` belt uit naar de Cloudflare-rand — er is
geen inbound web-poort nodig en het origin-IP blijft verborgen.

### Ubuntu 26.04: `ssh.service` én `ssh.socket`

26.04's `openssh-server`-postinst enabled beide units, en welke er luistert verschilt per
image. Dat raakt twee dingen, en het script vangt ze allebei af:

- **Herstart na hardening** — `reload ssh.service` faalt als sshd socket-geactiveerd
  draait. Het script detecteert de actieve unit in plaats van te gokken.
- **fail2ban** — Ubuntu's jail matcht op `_SYSTEMD_UNIT=ssh.service`. Onder
  socket-activatie heten de per-verbinding-units `ssh@<n>-….service` en matcht die regel
  **niets**: fail2ban bant dan stilzwijgend niemand. Het script schrijft daarom
  `/etc/fail2ban/jail.d/emeq-sshd.conf` met `journalmatch = _COMM=sshd`, wat in beide
  modi werkt. Controleer met `fail2ban-client status sshd` → `Journal matches: _COMM=sshd`.

Het script vult **geen** `.env.prod` en start de stack niet: daar gaan secrets in
(`APP_KEY`, `DB_PASSWORD`, tunnel-token). Dat is de handmatige stap hieronder.

## Vereisten (eenmalig)

1. Server geprovisioned (hierboven) — Docker Engine + compose-plugin.
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

Daarna: admin-user aanmaken (zie [Bootstrap van de admin-user](#bootstrap-van-de-admin-user)),
inloggen op `/admin`, Exact-credentials invullen onder Beheer → Integratie-instellingen,
en de Exact-tenant koppelen.

### Bootstrap van de admin-user

`php artisan db:seed` doet op productie **niets**: `DatabaseSeeder::run()` begint met
`if (app()->isProduction()) return;`. De enige weg naar een eerste user is
`EmeqStaffSeeder`, en die leest twee env-variabelen — ontbreken ze, dan stopt hij stil.

```bash
# in .env.prod, vóór het seeden
EMEQ_STAFF_SEED_EMAIL=…
EMEQ_STAFF_SEED_PASSWORD=…
```

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T app \
  php artisan db:seed --class=EmeqStaffSeeder --force
```

Levert één user met rol `super-admin`, plus de rolrijen `super-admin`/`staff`/`boekhouder`
en hun permissies. De seeder is bootstrap-only: bestaat de user al, dan gooit hij een
`RuntimeException` in plaats van het wachtwoord te overschrijven. Wachtwoord kwijt →
resetten via `tinker`, niet via de seeder.

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

Drie lagen:

1. **Geplande dagelijkse backup** — `spatie/laravel-backup` draait via de Laravel scheduler
   een `pg_dump` naar de lokale `backups`-disk, **AES-256-versleuteld** (env
   `BACKUP_ARCHIVE_PASSWORD` — leeg = onversleuteld), met getrapte retentie (7 d alles →
   dagelijks → wekelijks → maandelijks) en een dagelijkse `backup:monitor` health-check.
   Config: `config/backup.php`; planning: `routes/console.php`.
2. **Rauwe `pg_dump` (dependency-vrij)** — `make prod-backup` draait automatisch vóór elke
   deploy én nachtelijk via de systemd-timer `emeq-backup.timer` (04:00, 14 d-rotatie). Draait
   ongeacht app-health — ook als het app/scheduler-image stuk is. De belt naast spatie.
3. **Off-site DR** — **OVH Automated Backup** (VPS-optie) maakt de block-level off-site kopie
   als de server-schijf sneuvelt.

Handmatig:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T app php artisan backup:run --only-db
make prod-backup            # rauwe pg_dump → backups/emeq-hub-<timestamp>.sql.gz (gitignored)
```

Restore uit een spatie-zip:

```bash
# encrypted zip uitpakken (vraagt BACKUP_ARCHIVE_PASSWORD) → sql terugzetten
unzip -P "$BACKUP_ARCHIVE_PASSWORD" backups/emeq-hub/<timestamp>.zip -d /tmp/restore
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T db \
    psql -U emeq_hub -d emeq_hub < /tmp/restore/db-dumps/*.sql
```

Restore uit een rauwe pre-deploy dump:

```bash
gunzip -c backups/emeq-hub-<timestamp>.sql.gz \
  | docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T db \
      psql -U emeq_hub -d emeq_hub
```

> [#49](https://github.com/yusufkaracaburun/emeq-hub/issues/49): de drie lagen dekken
> verschillende failure-modes — snapshot = volledige-disk-DR, spatie/pg_dump = point-in-time
> logische restore van alleen de database.

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

## Schone herstart (`migrate:fresh` op prod)

`database/migrations` bevat sinds de consolidatie alleen `create`-bestanden: geen losse
`add_*`/`alter_*` meer, elke schema-wijziging werkt de create bij. Laravel slaat een
al-gedraaide migratie over, dus een kolom die je in zo'n bestand toevoegt landt op een
bestaande database **nooit** — `migrate` meldt "Nothing to migrate" en de app breekt
daarna op de ontbrekende kolom. Een schema-wijziging vraagt hier dus een volledige
herstart:

```bash
make prod-backup                       # altijd eerst — de fresh is onomkeerbaar
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T app \
  php artisan migrate:fresh --force
```

Wat je daarna **niet** vanzelf terugkrijgt, en dus vóóraf regelt:

| Weg | Terug via |
|---|---|
| Admin-user + rollen | [Bootstrap van de admin-user](#bootstrap-van-de-admin-user). `--seed` alleen doet niets op prod. |
| Exact client_id/secret/redirect_uri/webhook_secret | Vóór de fresh uitlezen en terugzetten, of opnieuw invullen onder Beheer → Integratie-instellingen. De settings-migratie zet ze **leeg** aan. |
| Consumers, PATs, Accounts, Connections | Opnieuw aanmaken in `/admin`; eindgebruikers koppelen opnieuw. |
| Privacy/voorwaarden, permissies, schema | Komen wél uit de migraties. |

De Exact-credentials oversteken zonder ze in je shell-history te zetten — schrijven en
lezen gebeurt binnen de container, de host ziet alleen een `chmod 600`-bestand dat je
achteraf wist:

```bash
docker compose … exec -T app php artisan tinker --execute \
  '$s = app(App\Settings\ExactSettings::class);
   file_put_contents("/tmp/x.json", json_encode(["client_id" => $s->client_id, …]));'
docker compose … cp app:/tmp/x.json ./exact.restore.json && chmod 600 exact.restore.json
# … fresh + seed …
docker compose … cp ./exact.restore.json app:/tmp/x.json
docker compose … exec -T app php artisan tinker --execute '/* terugzetten + save() */'
docker compose … exec -T app rm /tmp/x.json && shred -u exact.restore.json
```

Verifieer na afloop dat het nieuwe schema er echt staat (`Schema::getColumnListing`) in
plaats van alleen op een groene `/up` af te gaan: die controleert db-connectiviteit, niet
of jouw kolom bestaat.

## Smoke-test na elke release

```bash
curl -fsS https://hub.emeq.nl/up      # health via Cloudflare
make prod-ps                          # alles up/healthy
make prod-logs                        # app + horizon + tunnel
```

## Monitoring — wat er is, en wat er niet is

**Wat er is:**

- Container-healthchecks in `docker-compose.prod.yml` — herstarten een omgevallen
  container, maar melden niets naar buiten.
- `/up` checkt db + redis en geeft `{"status":"up","database":"ok","redis":"ok"}`.
- `backup:monitor` — dagelijkse health-check op de backup-keten (zie § Backup & restore).

**Wat er niet is:**

- Geen externe uptime-monitor. Valt de app om, of gaat cloudflared/db/redis onderuit,
  dan krijgt niemand een seintje — je merkt het pas als je zelf kijkt of iemand belt.
- Geen error-aggregatie. `sentry/sentry-laravel` zit in de codebase (`config/sentry.php`
  + `Integration::handles` in `bootstrap/app.php`) maar staat **uit**: er is geen DSN
  gezet. Dormant, niet actief.
- Logs gaan op `LOG_LEVEL=warning` naar `stderr` en daarmee naar Docker-daemon-rotatie.
  Geen sink, geen alerting, geen retentie voorbij de rotatie.

Dat gat is bekend en staat open als [#53](https://github.com/yusufkaracaburun/emeq-hub/issues/53).
Twee besluiten wachten daar op een keuze: welk meldkanaal, en Sentry activeren of de dep
verwijderen. Niets van dit alles is nu ingeregeld — ga er bij een incident niet vanuit dat
je gealarmeerd wordt.

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
