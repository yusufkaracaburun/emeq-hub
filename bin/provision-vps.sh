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
log "sleutel-bron: ${SOURCE_KEYS} ($(wc -l < "$SOURCE_KEYS") sleutel(s))"

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
log "docker log-rotatie"
cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
JSON
systemctl restart docker
systemctl enable docker

# ── 3. Deploy-user ────────────────────────────────────────────────────────────
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    log "user ${DEPLOY_USER} aanmaken"
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
usermod -aG docker "$DEPLOY_USER"

log "sleutels kopiëren naar ${DEPLOY_USER} (uit ${SOURCE_KEYS})"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
cp "$SOURCE_KEYS" "/home/${DEPLOY_USER}/.ssh/authorized_keys"
chown "${DEPLOY_USER}:${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh/authorized_keys"
chmod 600 "/home/${DEPLOY_USER}/.ssh/authorized_keys"

# make prod-* draait docker compose; sudo is verder niet nodig.
echo "${DEPLOY_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl, /usr/bin/apt-get" \
    > "/etc/sudoers.d/${DEPLOY_USER}"
chmod 440 "/etc/sudoers.d/${DEPLOY_USER}"

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
sshd_effective() { sshd -T 2>/dev/null || true; }
SSHD_CFG="$(sshd_effective)"

SSH_PORT="$(awk '/^port /{p=$2} END{print p}' <<<"$SSHD_CFG")"
SSH_PORT="${SSH_PORT:-22}"

log "ufw: alles dicht behalve SSH (poort ${SSH_PORT})"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow "${SSH_PORT}/tcp" comment 'SSH'
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
printf '  fail2ban    : %s\n' \
    "$(fail2ban-client status sshd 2>/dev/null | grep -E 'Currently failed|Journal matches' | tr -s ' \n' ' ' || echo 'jail NIET actief')"
printf '  repo        : %s\n' "$(sudo -u "$DEPLOY_USER" git -C "$APP_DIR" log --oneline -1)"

cat <<EOF

────────────────────────────────────────────────────────────────────────────
 Server klaar. Root-login is nu uit — verbind voortaan als: ${DEPLOY_USER}@<ip>

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
