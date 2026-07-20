#!/usr/bin/env bash
# Provisioning voor een verse Ubuntu-LTS-VPS die emeq-hub in prod draait.
# Getest tegen 26.04 (resolute); werkt op elke LTS die Docker indexeert.
#
# Draai op de kale server, als root of via sudo:
#   ssh hub 'sudo bash -s' < bin/provision-vps.sh     # OVH-image: user `ubuntu`
#   ssh root@<ip> 'bash -s' < bin/provision-vps.sh    # image met root-login
#
# Idempotent: opnieuw draaien is veilig.
#
# Wat het NIET doet: .env.prod vullen en de stack starten. Dat is een aparte,
# handmatige stap omdat er secrets in gaan (APP_KEY, DB_PASSWORD, tunnel-token).
# Zie docs/deployment.md § Eerste deploy.

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/yusufkaracaburun/emeq-hub.git}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="/home/${DEPLOY_USER}/emeq-hub"
HOSTNAME_FQDN="${HOSTNAME_FQDN:-hub.emeq.nl}"

log()  { printf "\n\033[1;36m==>\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m  ⚠ %s\033[0m\n" "$*" >&2; }
die()  { printf "\n\033[1;31m✖\033[0m %s\n" "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "draai dit als root (of via sudo)"

# DEPLOY_USER is override-baar en belandt zowel in een unix-account als in een
# bestandsnaam onder /etc/sudoers.d/. Sudo negeert daar stilzwijgend elk bestand
# met een punt of een tilde in de naam: `DEPLOY_USER=deploy.v2` levert dan een
# user zónder sudo-rechten, zonder één foutmelding. Afvangen vóór alles.
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] \
    || die "DEPLOY_USER '${DEPLOY_USER}' is geen geldige unix-naam (a-z, 0-9, _ en -; geen punt)"

# ── 0. Sleutel-check vóór alles ───────────────────────────────────────────────
# SSH-hardening zet wachtwoord-login en root-login uit. Zonder een werkende
# publieke sleutel sluit je jezelf buiten en is de enige weg terug OVH's
# rescue-mode. Dus: eerst bewijzen dat er een sleutel is.
#
# Waar die sleutel staat verschilt per image: op OVH's Ubuntu is er geen
# root-login en zit hij op de sudo-user (`ubuntu`); op een image mét root-login
# staat hij in /root. Zoek 'm dus bij de gebruiker die dit script aanroept, en
# val terug op root.
if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    SOURCE_KEYS="$(getent passwd "$SUDO_USER" | cut -d: -f6)/.ssh/authorized_keys"
else
    SOURCE_KEYS="/root/.ssh/authorized_keys"
fi

[[ -s "$SOURCE_KEYS" ]] || die "geen sleutel in ${SOURCE_KEYS} — zet er eerst één neer (ssh-copy-id), anders sluit de hardening je buiten"

# Tellen op inhoud, niet op newlines: `wc -l` telt een sleutel zónder afsluitende
# newline als 0, en dan meldt precies de regel die vertrouwen moet wekken "0
# sleutel(s)" terwijl het script vrolijk doorgaat met hardenen.
KEY_COUNT="$(grep -cvE '^[[:space:]]*(#|$)' "$SOURCE_KEYS" || true)"
[[ "$KEY_COUNT" -gt 0 ]] || die "${SOURCE_KEYS} bevat geen enkele sleutel (alleen lege regels of comments)"
log "sleutel-bron: ${SOURCE_KEYS} (${KEY_COUNT} sleutel(s))"

# ── 1. Basis ──────────────────────────────────────────────────────────────────
log "apt update + upgrade"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

# `sudo` staat er expliciet bij: het script schrijft /etc/sudoers.d/<user> en
# draait de git-clone als de deploy-user. Op een minimaal image bestaat
# /etc/sudoers.d niet en klapt dat. Idem tzdata — zonder dat pakket kent
# timedatectl 'Europe/Amsterdam' niet.
# `adduser` staat er expliciet bij: het zit níét in elk Ubuntu-image (kaal
# 26.04 heeft alleen `useradd`), en zonder dat pakket klapt § 3.
apt-get install -y -qq \
    ca-certificates curl gnupg git make sudo adduser tzdata openssh-server \
    ufw fail2ban unattended-upgrades

# Cosmetisch. Een falende hostname of timezone mag de provisioning niet
# afbreken — vandaar een waarschuwing in plaats van een `set -e`-exit.
log "hostname → ${HOSTNAME_FQDN}, timezone → Europe/Amsterdam"
hostnamectl set-hostname "$HOSTNAME_FQDN" || warn "hostname niet gezet (niet fataal)"
timedatectl set-timezone Europe/Amsterdam || warn "timezone niet gezet (niet fataal)"

# Security-patches automatisch — Exact vraagt in de D&S-review naar
# patch-management. Dit is het antwoord.
log "unattended-upgrades aan (security-only)"
dpkg-reconfigure -f noninteractive unattended-upgrades

# ── 1b. Swap ──────────────────────────────────────────────────────────────────
# 12 GB RAM zonder swap: een geheugenpiek (Octane-worker, migratie, build) laat de
# OOM-killer willekeurig processen slopen i.p.v. uit te wijken naar disk. 4 GB swap
# als vangnet + lage swappiness (RAM-first, swap pas onder echte druk).
SWAPFILE="/swapfile"
if swapon --show=NAME --noheadings 2>/dev/null | grep -qx "$SWAPFILE"; then
    log "swap al actief — overslaan"
else
    if [[ ! -e "$SWAPFILE" ]]; then
        log "swapfile aanmaken (4 GB)"
        fallocate -l 4G "$SWAPFILE" || dd if=/dev/zero of="$SWAPFILE" bs=1M count=4096 status=none
        chmod 600 "$SWAPFILE"
        mkswap "$SWAPFILE" >/dev/null
    fi
    swapon "$SWAPFILE"
    grep -qxF "${SWAPFILE} none swap sw 0 0" /etc/fstab || echo "${SWAPFILE} none swap sw 0 0" >> /etc/fstab
fi
if [[ "$(cat /proc/sys/vm/swappiness)" != "10" ]]; then
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-emeq-swappiness.conf
    sysctl -q -w vm.swappiness=10
fi

# ── 2. Docker Engine + compose-plugin (officiële repo, niet de apt-versie) ────
if ! command -v docker >/dev/null 2>&1; then
    log "Docker Engine installeren"

    # Docker's repo indexeert per Ubuntu-codename. Ontbreekt die, dan faalt
    # `apt-get install docker-ce` met een cryptische 404 op een Packages-index.
    # Liever hier stoppen met een leesbare melding.
    CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
    curl -fsSI "https://download.docker.com/linux/ubuntu/dists/${CODENAME}/Release" >/dev/null 2>&1 \
        || die "Docker heeft geen repo voor Ubuntu '${CODENAME}' — kies een LTS die Docker wél indexeert (https://download.docker.com/linux/ubuntu/dists/)"

    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu ${CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
    log "Docker al aanwezig ($(docker --version))"
fi

# Zonder rotatie vreet één spammy container de 100 GB disk op en valt Postgres
# om op ENOSPC.
#
# `systemctl restart docker` bounce't álle containers (app, horizon, scheduler, db,
# redis). Op een re-run tegen een levende box mag dat niet gebeuren als daemon.json
# ongewijzigd is — dus alleen herschrijven + herstarten bij een echte content-change.
log "docker log-rotatie"
DAEMON_JSON="/etc/docker/daemon.json"
DAEMON_TMP="$(mktemp)"
cat > "$DAEMON_TMP" <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
JSON
if [[ -f "$DAEMON_JSON" ]] && cmp -s "$DAEMON_TMP" "$DAEMON_JSON"; then
    rm -f "$DAEMON_TMP"
    log "daemon.json ongewijzigd — docker niet herstart"
else
    install -m 644 "$DAEMON_TMP" "$DAEMON_JSON"
    rm -f "$DAEMON_TMP"
    log "daemon.json bijgewerkt — docker herstarten"
    systemctl restart docker
fi
systemctl enable docker

# ── 3. Deploy-user ────────────────────────────────────────────────────────────
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    log "user ${DEPLOY_USER} aanmaken"
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
usermod -aG docker "$DEPLOY_USER"

DEPLOY_KEYS="/home/${DEPLOY_USER}/.ssh/authorized_keys"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"

# Bron en doel kunnen hetzelfde bestand zijn (DEPLOY_USER=ubuntu, of een re-run
# waarbij de aanroeper de deploy-user zelf is). `cp` faalt daar op, en met `set -e`
# sterft het script precies vóór de hardening — halve provisioning.
if [[ "$SOURCE_KEYS" == "$DEPLOY_KEYS" ]]; then
    log "sleutel-bron ís al ${DEPLOY_USER}'s authorized_keys — niets te kopiëren"
else
    # Appenden + dedupen i.p.v. overschrijven: een later toegevoegde sleutel (CI,
    # tweede beheerder) mag bij een re-run niet verdwijnen. Bestaande regels eerst,
    # dan de bron; comment-/lege regels eruit; dedupe op exacte regel (volgorde-behoud).
    log "sleutels mergen naar ${DEPLOY_USER} (uit ${SOURCE_KEYS}, append + dedupe)"
    MERGED_KEYS="$(mktemp)"
    { [[ -f "$DEPLOY_KEYS" ]] && cat "$DEPLOY_KEYS"; cat "$SOURCE_KEYS"; } \
        | grep -vE '^[[:space:]]*(#|$)' \
        | awk '!seen[$0]++' > "$MERGED_KEYS"
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$MERGED_KEYS" "$DEPLOY_KEYS"
    rm -f "$MERGED_KEYS"
fi

# make prod-* draait docker compose; systemctl/apt-get zijn wat er verder nodig is.
# Dit is geen privilege-grens: `docker`-groep is root-equivalent en apt-get kent
# --Pre-Invoke-hooks. Het beperkt per ongeluk-schade, niet een aanvaller.
#
# Rechtstreeks naar /etc/sudoers.d/ schrijven is niet veilig: een syntaxfout maakt
# sudo systeembreed onbruikbaar (elk bestand in sudoers.d wordt geparsed), en dat
# merk je pas ná de hardening. Dus eerst valideren met visudo, dán installeren.
SUDOERS_TMP="$(mktemp)"
echo "${DEPLOY_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl, /usr/bin/apt-get" > "$SUDOERS_TMP"
visudo -cqf "$SUDOERS_TMP" \
    || { rm -f "$SUDOERS_TMP"; die "sudoers-regel voor ${DEPLOY_USER} is ongeldig — niet geïnstalleerd"; }
install -m 440 -o root -g root "$SUDOERS_TMP" "/etc/sudoers.d/${DEPLOY_USER}"
rm -f "$SUDOERS_TMP"

# ── 4. Repo ───────────────────────────────────────────────────────────────────
if [[ -d "${APP_DIR}/.git" ]]; then
    log "repo bestaat al — pull"
    sudo -u "$DEPLOY_USER" git -C "$APP_DIR" pull --ff-only
else
    log "repo clonen → ${APP_DIR}"
    sudo -u "$DEPLOY_USER" git clone --branch master "$REPO_URL" "$APP_DIR"
fi

# ── 5. Firewall ───────────────────────────────────────────────────────────────
# Poort 80/443 blijven DICHT: cloudflared bouwt een uitgaande verbinding naar de
# Cloudflare-rand. Het origin heeft geen inbound web-poort nodig en het IP blijft
# verborgen. Alleen SSH in.
# Niet `ufw allow OpenSSH`: dat app-profiel bestaat alleen als openssh-server
# geïnstalleerd is, en het gokt op poort 22. Vraag sshd zélf op welke poort hij
# luistert — een verkeerde regel hier betekent buitengesloten.
#
# `sshd -T` één keer in een variabele, en geen `exit`/`-q` in de consumers:
# een vroeg-stoppende awk/grep stuurt SIGPIPE naar sshd, en met `pipefail` +
# `set -e` sterft het script daarop. Dat is bovendien een race — het hangt
# ervan af of de output nog in de pipe-buffer past.
#
# En vraag het de júíste bron. Draait sshd socket-geactiveerd, dan komt de
# luisterpoort van ssh.socket's ListenStream en niet uit sshd_config: `sshd -T`
# zegt dan 22 terwijl er op iets anders geluisterd wordt. De ufw-regel staat dan
# op de verkeerde poort — en dat merk je pas bij de vólgende verbinding, want de
# huidige sessie leeft door. Socket eerst, sshd_config als terugval.
sshd_effective() { sshd -T 2>/dev/null || true; }
SSHD_CFG="$(sshd_effective)"

ssh_socket_port() {
    systemctl is-active --quiet ssh.socket || return 0
    systemctl show ssh.socket -p Listen --value 2>/dev/null \
        | awk '{ n = split($1, a, ":"); if (p == "" && a[n] ~ /^[0-9]+$/) { p = a[n] } } END { print p }'
}

SSH_PORT="$(ssh_socket_port)"
[[ -n "$SSH_PORT" ]] || SSH_PORT="$(awk '/^port /{p=$2} END{print p}' <<<"$SSHD_CFG")"
SSH_PORT="${SSH_PORT:-22}"

log "ufw: alles dicht behalve SSH (poort ${SSH_PORT}, rate-limited)"
# Géén `ufw --force reset`: dat wist stilzwijgend operator-regels (bv. een tijdelijke
# allow die iemand ná de provisioning toevoegde). De defaults + SSH-limit zijn zelf
# idempotent — ufw dedupet een identieke regel bij een re-run — dus laat bestaande
# regels staan i.p.v. de tabel te legen.
ufw default deny incoming
ufw default allow outgoing
# `limit` i.p.v. `allow`: ufw's ingebouwde rate-limit blokkeert een bron-IP dat 6+
# connecties in 30s opent. Vult fail2ban aan als eerste laag tegen SSH-brute-force.
ufw limit "${SSH_PORT}/tcp" comment 'SSH (rate-limited)'
ufw --force enable
ufw status verbose

# Ubuntu's eigen jail matcht op `_SYSTEMD_UNIT=ssh.service`. Draait sshd onder
# socket-activatie, dan heten de per-verbinding-units `ssh@<n>-…​.service` en
# matcht die regel niets: fail2ban bant dan stilzwijgend niemand. Matchen op
# `_COMM=sshd` werkt in beide modi.
log "fail2ban: sshd-jail (journalmatch op _COMM, niet op unit-naam)"
cat > /etc/fail2ban/jail.d/emeq-sshd.conf <<'JAIL'
[sshd]
enabled = true
backend = systemd
journalmatch = _COMM=sshd
maxretry = 5
bantime = 1h
JAIL
systemctl enable --now fail2ban
systemctl restart fail2ban

# ── 5b. Dagelijkse backup-timer ───────────────────────────────────────────────
# `make prod-backup` draait nu alleen vóór een deploy — dagen zonder deploy = geen
# dump. Een systemd-timer draait 'm elke nacht als de deploy-user (docker-groep).
# ConditionPathExists: sla stil over zolang .env.prod nog niet bestaat (vóór de
# eerste deploy), i.p.v. een falende run te loggen. Off-site kopie = aparte stap.
log "backup-timer (dagelijks 04:00, als ${DEPLOY_USER})"
cat > /etc/systemd/system/emeq-backup.service <<SERVICE
[Unit]
Description=emeq-hub Postgres-backup (pg_dump)
After=docker.service
Requires=docker.service
ConditionPathExists=${APP_DIR}/.env.prod

[Service]
Type=oneshot
User=${DEPLOY_USER}
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/make prod-backup
SERVICE
cat > /etc/systemd/system/emeq-backup.timer <<'TIMER'
[Unit]
Description=Dagelijkse emeq-hub-backup

[Timer]
OnCalendar=*-*-* 04:00:00
Persistent=true

[Install]
WantedBy=timers.target
TIMER
systemctl daemon-reload
systemctl enable --now emeq-backup.timer

# ── 6. SSH-hardening (als laatste — pas als de deploy-user een sleutel heeft) ──
#
# Naam begint met 00 en dat is essentieel. OpenSSH hanteert
# first-obtained-value-wins en leest de drop-ins alfabetisch. OVH's image levert
# 50-cloud-init.conf met `PasswordAuthentication yes`; een 99-bestand komt dáár
# ná en wordt genegeerd. De hardening lijkt dan te slagen terwijl wachtwoord-
# login gewoon aan blijft staan. Alleen sorteren vóór 50 wint.
HARDENING="/etc/ssh/sshd_config.d/00-emeq-hardening.conf"
rm -f /etc/ssh/sshd_config.d/99-emeq-hardening.conf   # eerdere, verliezende versie

log "SSH: key-only, geen root-login (${HARDENING})"
cat > "$HARDENING" <<'SSHCONF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitEmptyPasswords no
X11Forwarding no
SSHCONF

sshd -t || die "sshd-config invalid — herstel of verwijder ${HARDENING}"

# 26.04 levert ssh.service én ssh.socket, en de postinst enabled ze allebei.
# Welke er luistert verschilt per image, dus: detecteren, niet aannemen.
# `reload ssh` op een niet-draaiende unit faalt, en met `set -e` sneuvelt het
# script precies ná het schrijven van de hardening.
if systemctl is-active --quiet ssh.socket; then
    log "sshd draait socket-geactiveerd → ssh.socket herstarten"
    systemctl restart ssh.socket
elif systemctl is-active --quiet ssh.service; then
    log "sshd draait als ssh.service → herladen"
    systemctl reload ssh.service
else
    warn "sshd draaide niet — ssh.service starten"
    systemctl enable --now ssh.service
fi

# ── 7. Bewijs, geen belofte ───────────────────────────────────────────────────
#
# Assert, niet alleen printen. Een drop-in wegschrijven bewijst niet dat sshd
# 'm honoreert (zie de 50-cloud-init-val hierboven) — dus vraag sshd zelf wat
# er effectief geldt en stop als dat niet klopt.
log "hardening verifiëren tegen sshd -T"
SSHD_CFG="$(sshd_effective)"          # opnieuw ophalen: sshd is net herladen
for want in "permitrootlogin no" "passwordauthentication no" "permitemptypasswords no"; do
    if grep -qx "$want" <<<"$SSHD_CFG"; then
        printf '  ✓ %s\n' "$want"
    else
        got="$(grep -E "^${want%% *} " <<<"$SSHD_CFG" || echo '(niet gezet)')"
        die "hardening NIET effectief — verwacht '${want}', sshd zegt '${got}'. Controleer de drop-in-volgorde in /etc/ssh/sshd_config.d/ (first-obtained-value-wins)."
    fi
done

log "verificatie"
printf '  docker      : %s\n' "$(docker --version)"
printf '  compose     : %s\n' "$(docker compose version --short)"
printf '  ufw         : %s\n' "$(ufw status | head -1)"
printf '  swap        : %s\n' "$(swapon --show=SIZE --noheadings 2>/dev/null | head -1 || echo 'geen')"
printf '  backup-timer: %s\n' "$(systemctl is-enabled emeq-backup.timer 2>/dev/null || echo 'niet actief')"
printf '  fail2ban    : %s\n' \
    "$(fail2ban-client status sshd 2>/dev/null | grep -E 'Currently failed|Journal matches' | tr -s ' \n' ' ' || echo 'jail NIET actief')"
printf '  repo        : %s\n' "$(sudo -u "$DEPLOY_USER" git -C "$APP_DIR" log --oneline -1)"

cat <<EOF

────────────────────────────────────────────────────────────────────────────
 Server klaar. Wachtwoord- en root-login staan nu UIT.

 ⚠ SLUIT DEZE SESSIE NOG NIET. Test eerst, in een tweede terminal, of
   ${DEPLOY_USER} werkt. Klopt de sleutel-setup niet, dan is deze open sessie
   je enige weg terug — anders rest OVH's rescue-mode.

     ssh ${DEPLOY_USER}@<ip> 'whoami && docker ps && sudo -n systemctl --version >/dev/null && echo OK'

   Krijg je '${DEPLOY_USER}' … 'OK' → deze sessie mag dicht.
   Krijg je 'Permission denied' → herstel hier, met de sessie nog open:

     sudo rm ${HARDENING} && sudo systemctl restart ssh.socket 2>/dev/null \\
       || sudo systemctl reload ssh.service

 Volgende stappen (handmatig, want secrets):

   1. cd ${APP_DIR}
   2. cp .env.prod.example .env.prod
   3. Vul in .env.prod:
        APP_KEY                  docker compose -f docker-compose.prod.yml \\
                                   run --rm app php artisan key:generate --show
        DB_PASSWORD              (genereer: openssl rand -base64 32)
        CLOUDFLARE_TUNNEL_TOKEN  Zero-Trust → Networks → Tunnels → connector-token
        APP_URL=https://${HOSTNAME_FQDN}
   4. make prod-up
   5. curl -fsS http://127.0.0.1:8090/up
   6. curl -fsS https://${HOSTNAME_FQDN}/up

 Zie docs/deployment.md.
────────────────────────────────────────────────────────────────────────────
EOF
