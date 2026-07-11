#!/usr/bin/env bash
# Provisioning voor een verse Ubuntu-24.04-VPS die emeq-hub in prod draait.
#
# Draai als root op de kale server:
#   ssh root@<ip> 'bash -s' < bin/provision-vps.sh
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

log() { printf "\n\033[1;36m==>\033[0m %s\n" "$*"; }
die() { printf "\n\033[1;31m✖\033[0m %s\n" "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "draai dit als root"

# ── 0. Sleutel-check vóór alles ───────────────────────────────────────────────
# SSH-hardening zet wachtwoord-login en root-login uit. Zonder een werkende
# publieke sleutel sluit je jezelf buiten en is de enige weg terug OVH's
# rescue-mode. Dus: eerst bewijzen dat er een sleutel is.
ROOT_KEYS="/root/.ssh/authorized_keys"
[[ -s "$ROOT_KEYS" ]] || die "geen sleutel in ${ROOT_KEYS} — voeg er eerst één toe (ssh-copy-id), anders lock je jezelf buiten"

# ── 1. Basis ──────────────────────────────────────────────────────────────────
log "hostname → ${HOSTNAME_FQDN}"
hostnamectl set-hostname "$HOSTNAME_FQDN"
timedatectl set-timezone Europe/Amsterdam

log "apt update + upgrade"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    ca-certificates curl gnupg git make ufw fail2ban unattended-upgrades

# Security-patches automatisch — Exact vraagt in de D&S-review naar
# patch-management. Dit is het antwoord.
log "unattended-upgrades aan (security-only)"
dpkg-reconfigure -f noninteractive unattended-upgrades

# ── 2. Docker Engine + compose-plugin (officiële repo, niet de apt-versie) ────
if ! command -v docker >/dev/null 2>&1; then
    log "Docker Engine installeren"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
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

log "root-sleutels kopiëren naar ${DEPLOY_USER}"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
cp "$ROOT_KEYS" "/home/${DEPLOY_USER}/.ssh/authorized_keys"
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
log "ufw: alles dicht behalve SSH"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw --force enable
ufw status verbose

log "fail2ban aan (sshd-jail)"
systemctl enable --now fail2ban

# ── 6. SSH-hardening (als laatste — pas als de deploy-user een sleutel heeft) ──
log "SSH: key-only, geen root-login"
cat > /etc/ssh/sshd_config.d/99-emeq-hardening.conf <<'SSHCONF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitEmptyPasswords no
X11Forwarding no
SSHCONF

sshd -t || die "sshd-config invalid — hardening NIET toegepast, herstel /etc/ssh/sshd_config.d/99-emeq-hardening.conf"
systemctl reload ssh

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
