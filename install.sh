#!/usr/bin/env bash
# Kelidban license server - installer and management menu.
#
# One-line install (opens the menu):
#   curl -fsSL https://raw.githubusercontent.com/silent4time/kelidban/main/install.sh | sudo bash
#
# From a checkout or a zip:
#   sudo bash /opt/kelidban-src/install.sh            menu (when run in a terminal)
#   sudo bash install.sh /root/kelidban.zip           install from a zip of this repo
#   sudo kelidban menu                                menu, after the first install
#
# Actions (skip the menu):
#   install  update  ssl  renew  check  status  url  reset-password
#   restart  logs  backup  restore  uninstall  help  menu
#
# Options:  -y, --yes       no questions (use defaults); implies "install" if no action is given
#           --dir PATH      install path (default /home/kelidban)
#           --domain HOST   domain for the server (e.g. license.example.com)
#           --email ADDR    email for the Let's Encrypt certificate
#           --branch NAME   git branch to install from (default main)
#           --no-upgrade    do not run apt upgrade on a fresh install
#
# Never touches: config.php (except base_url / force_https), storage/ (database), the panel folder public/kb-*.
set -euo pipefail

REPO_URL="${KELIDBAN_REPO:-https://github.com/silent4time/kelidban.git}"
BRANCH="${KELIDBAN_BRANCH:-main}"
SRC_CLONE="/opt/kelidban-src"
TARGET="/home/kelidban"
DOMAIN=""
EMAIL=""
ASSUME_YES=0
NO_UPGRADE=0
ZIP=""
ACTION=""
BACKUP_DIR="/root/kelidban-backups"
CRED_FILE="/root/kelidban-admin.txt"
VHOST="/etc/apache2/sites-available/kelidban.conf"
VHOST_SSL="/etc/apache2/sites-available/kelidban-le-ssl.conf"
DEFAULT_MARK="$BACKUP_DIR/.default-site-disabled"
TS="$(date +%Y%m%d-%H%M%S)"
OLD_CANDIDATES=(/home/license_server /home/license-server /var/www/license_server /var/www/license-server)
WEB_USER="www-data"
ORIG_ARGS=("$@")

if [[ -t 1 ]]; then C_R=$'\e[31m'; C_G=$'\e[32m'; C_Y=$'\e[33m'; C_B=$'\e[1m'; C_0=$'\e[0m'; else C_R=""; C_G=""; C_Y=""; C_B=""; C_0=""; fi
say()  { echo "[*] $*"; }
ok()   { echo "${C_G}[OK]${C_0} $*"; }
warn() { echo "${C_Y}[!]${C_0} $*"; }
err()  { echo "${C_R}[x]${C_0} $*" >&2; }
die()  { err "$*"; exit 1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "run as root: sudo bash install.sh"
export DEBIAN_FRONTEND=noninteractive
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

# ---------- arguments ----------
while [[ $# -gt 0 ]]; do
  case "$1" in
    install|update|ssl|renew|check|selfcheck|doctor|status|url|creds|reset-password|restart|logs|backup|restore|uninstall|help|menu)
      ACTION="$1"; [[ "$ACTION" == "selfcheck" || "$ACTION" == "doctor" ]] && ACTION="check" ;;
    -y|--yes) ASSUME_YES=1 ;;
    --dir) TARGET="${2:?--dir needs a path}"; shift ;;
    --domain) DOMAIN="${2:?--domain needs a host}"; shift ;;
    --email) EMAIL="${2:?--email needs an address}"; shift ;;
    --branch) BRANCH="${2:?--branch needs a name}"; shift ;;
    --no-upgrade) NO_UPGRADE=1 ;;
    -h|--help) ACTION="help" ;;
    *.zip) ZIP="$1" ;;
    *) die "unknown option: $1 (try: sudo bash install.sh help)" ;;
  esac
  shift
done
TARGET="${TARGET%/}"
[[ -n "$TARGET" && "$TARGET" != "/" ]] || die "invalid --dir"

# A terminal we can ask questions on (also works with: curl ... | sudo bash)
HAVE_TTY=0
if { : </dev/tty; } 2>/dev/null; then HAVE_TTY=1; fi
interactive() { [[ $ASSUME_YES -eq 0 && $HAVE_TTY -eq 1 ]]; }

ask() {
  local q="$1" def="${2:-}" a=""
  if ! interactive; then echo "$def"; return; fi
  if [[ -n "$def" ]]; then q="$q [$def]"; fi
  read -r -p "$q " a </dev/tty || true
  echo "${a:-$def}"
}
confirm() {     # default yes
  interactive || return 0
  local a; a="$(ask "$1 [Y/n]" "")"
  [[ -z "$a" || "$a" =~ ^[Yy] ]]
}
confirm_no() {  # default no; -y answers no as well
  interactive || return 1
  local a; a="$(ask "$1 [y/N]" "")"
  [[ "$a" =~ ^[Yy] ]]
}
pause() { interactive && { read -r -p "Press Enter to continue..." _ </dev/tty || true; }; return 0; }

# ---------- source code (git clone in /opt/kelidban-src) ----------
SELF_DIR=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
  SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi
is_src()     { [[ -f "$1/lib/bootstrap.php" && -f "$1/app/admin.php" && -f "$1/public/api/verify.php" && -f "$1/install.sh" ]]; }
is_install() { [[ -f "$1/lib/bootstrap.php" && -f "$1/public/api/verify.php" ]]; }

need_pkg() {
  local miss=() p
  for p in "$@"; do dpkg -s "$p" >/dev/null 2>&1 || miss+=("$p"); done
  [[ ${#miss[@]} -eq 0 ]] && return 0
  say "Installing packages: ${miss[*]}"
  apt-get update -y >/dev/null || warn "apt-get update reported errors"
  apt-get install -y "${miss[@]}" >/dev/null || die "apt-get install ${miss[*]} failed"
}

# Clone or fast-forward /opt/kelidban-src to the latest $BRANCH
sync_src() {
  command -v git >/dev/null 2>&1 || need_pkg git ca-certificates
  if [[ -d "$SRC_CLONE/.git" ]]; then
    say "Fetching the latest code ($BRANCH) into $SRC_CLONE"
    git -C "$SRC_CLONE" remote set-url origin "$REPO_URL" 2>/dev/null || true
    git -C "$SRC_CLONE" fetch -q origin "+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH" \
      || die "git fetch failed (network? branch '$BRANCH' exists?)"
    git -C "$SRC_CLONE" checkout -q -B "$BRANCH" "origin/$BRANCH" \
      || die "git checkout failed; local changes in $SRC_CLONE? (git -C $SRC_CLONE status)"
  else
    if [[ -e "$SRC_CLONE" ]]; then
      mv "$SRC_CLONE" "$SRC_CLONE.old-$TS"
      warn "$SRC_CLONE was not a git checkout; moved to $SRC_CLONE.old-$TS"
    fi
    say "Downloading Kelidban ($BRANCH) into $SRC_CLONE"
    git clone -q -b "$BRANCH" "$REPO_URL" "$SRC_CLONE" || die "git clone $REPO_URL failed (network?)"
  fi
  ok "Source: $SRC_CLONE at $(git -C "$SRC_CLONE" rev-parse --short HEAD)"
}

# Run through curl | bash (no files next to us): fetch the repo, then run its copy of this script.
if [[ -z "$ZIP" && ! -f "${SELF_DIR:-/nonexistent}/lib/bootstrap.php" ]]; then
  sync_src
  exec env KELIDBAN_SYNCED=1 KELIDBAN_BRANCH="$BRANCH" bash "$SRC_CLONE/install.sh" "${ORIG_ARGS[@]}"
fi

# ---------- helpers ----------
pick_php() {
  local p
  for p in php8.4 php8.3 php8.2 php8.1 php; do
    if command -v "$p" >/dev/null 2>&1 && "$p" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; then
      command -v "$p"
      return 0
    fi
  done
  return 1
}
kb() { "$TARGET/bin/kelidban" "$@"; }   # the installed server tool (runs PHP as root, fixes storage owner)
installed() { is_install "$TARGET" && [[ -f "$TARGET/config.php" ]]; }
need_installed() { installed || die "Kelidban is not installed in $TARGET (choose Install first)"; }

version_of() { head -1 "$1/VERSION" 2>/dev/null | sed 's/\\n$//' | tr -d '[:space:]' || true; }

is_ipv4() { [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; }
# "HTTPS://Lic.Example.com:443/path " -> "lic.example.com"
normalize_host() {
  local h="$1"
  h="$(echo "$h" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
  h="${h#http://}"; h="${h#https://}"
  h="${h%%/*}"; h="${h%%\?*}"
  h="${h%:*}"; h="${h%.}"
  echo "$h"
}
valid_domain() { [[ "$1" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]]; }

PUBLIC_IP=""
public_ip() {
  if [[ -z "$PUBLIC_IP" ]]; then
    local u ip
    for u in https://api.ipify.org https://ipv4.icanhazip.com https://ifconfig.me/ip https://checkip.amazonaws.com; do
      ip="$(curl -4 -fsS --max-time 5 "$u" 2>/dev/null | tr -d '[:space:]' || true)"
      if is_ipv4 "$ip"; then PUBLIC_IP="$ip"; break; fi
    done
    if [[ -z "$PUBLIC_IP" ]]; then
      PUBLIC_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"
    fi
    if [[ -z "$PUBLIC_IP" ]]; then
      PUBLIC_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
    fi
  fi
  echo "$PUBLIC_IP"
}
resolve4() { getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true; }
dns_points_here() {   # 0 = the domain resolves to this server's public IP
  local ips me; ips="$(resolve4 "$1")"; me="$(public_ip)"
  [[ -n "$me" && " $ips " == *" $me "* ]]
}

cfg_get() { kb config-get "$1" 2>/dev/null || true; }
cfg_set() { kb config-set "$1" "$2" >/dev/null || die "could not set $1 in $TARGET/config.php"; }
base_url() { cfg_get base_url; }
base_host() { normalize_host "$(base_url)"; }
base_scheme() { local u; u="$(base_url)"; echo "${u%%://*}"; }
panel_url() { kb url 2>/dev/null | head -1 || true; }

port_owner() {   # process names listening on TCP port $1
  ss -H -ltnp "( sport = :$1 )" 2>/dev/null | grep -o 'users:(("[^"]*"' | sed 's/users:(("//; s/"$//' | sort -u | tr '\n' ' ' || true
}

rand_pw() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20; }

# ---------- firewall ----------
open_firewall() {
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null && ok "ufw: ports 80 and 443 allowed"
  fi
  if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
    firewall-cmd -q --permanent --add-service=http --add-service=https && firewall-cmd -q --reload && ok "firewalld: http and https allowed"
  fi
  return 0
}

# ---------- Apache ----------
apache_ok() { apache2ctl configtest >/dev/null 2>&1; }

ensure_apache() {
  local php_ver
  need_pkg apache2 libapache2-mod-php php-cli php-sqlite3 php-curl php-mbstring certbot python3-certbot-apache curl rsync ca-certificates iproute2
  local own; own="$(port_owner 80)"
  if [[ -n "$own" && "$own" != *apache2* ]]; then
    err "Port 80 is used by: $own (not Apache). Kelidban needs Apache on ports 80/443."
    if [[ "$own" == *nginx* ]] && confirm "Stop and disable nginx so Apache can use port 80?"; then
      systemctl disable --now nginx || die "could not stop nginx"
    else
      die "free port 80 first (for nginx: systemctl disable --now nginx), then run again"
    fi
  fi
  local m
  for m in rewrite headers ssl; do
    [[ -e "/etc/apache2/mods-enabled/$m.load" ]] || a2enmod -q "$m" >/dev/null
  done
  if ! apache2ctl -M 2>/dev/null | grep -q 'php[0-9._]*_module'; then
    php_ver="$(find /etc/apache2/mods-available -name 'php*.load' -printf '%f\n' 2>/dev/null | sed 's/\.load$//' | sort -V | tail -1 || true)"
    if [[ -n "$php_ver" ]]; then
      a2dismod -q mpm_event >/dev/null 2>&1 || true
      a2enmod -q mpm_prefork >/dev/null 2>&1 || true
      a2enmod -q "$php_ver" >/dev/null || warn "could not enable $php_ver"
    else
      warn "no Apache PHP module found (apt-get install libapache2-mod-php)"
    fi
  fi
  systemctl enable -q apache2 2>/dev/null || true
}

restart_apache() {
  apache_ok || { apache2ctl configtest || true; die "Apache config test failed (see above)"; }
  systemctl restart apache2 || { journalctl -u apache2 -n 20 --no-pager || true; die "Apache failed to start (see above)"; }
}

# Kelidban's port-80 site. Name = domain (or IP); the IP is an alias so http://IP/ also works.
write_vhost() {
  local name="$1" alias="${2:-}" tmp
  tmp="$(mktemp)"
  {
    echo "# Managed by Kelidban install.sh"
    echo "<VirtualHost *:80>"
    echo "    ServerName ${name}"
    [[ -n "$alias" && "$alias" != "$name" ]] && echo "    ServerAlias ${alias}"
    echo "    DocumentRoot ${TARGET}/public"
    echo "    <Directory ${TARGET}/public>"
    echo "        AllowOverride All"
    echo "        Require all granted"
    echo "    </Directory>"
    echo "    ErrorLog \${APACHE_LOG_DIR}/kelidban-error.log"
    echo "    CustomLog \${APACHE_LOG_DIR}/kelidban-access.log combined"
    echo "</VirtualHost>"
  } > "$tmp"
  if [[ -f "$VHOST" ]] && ! grep -q 'Managed by Kelidban' "$VHOST"; then
    cp -a "$VHOST" "$BACKUP_DIR/vhost-kelidban.conf.$TS"
  fi
  if ! cmp -s "$tmp" "$VHOST" 2>/dev/null; then
    install -m 644 "$tmp" "$VHOST"
    ok "Web site config written: $VHOST (ServerName $name)"
  fi
  rm -f "$tmp"
  a2ensite -q kelidban.conf >/dev/null
}

# kelidban.conf exists and is ours (written by this script or the older installer)
own_vhost() { [[ -f "$VHOST" ]] && { grep -q 'Managed by Kelidban' "$VHOST" || grep -qF "$TARGET/public" "$VHOST"; }; }

# The stock "It works" site would answer http://IP/ instead of Kelidban
disable_stock_default() {
  local f=/etc/apache2/sites-enabled/000-default.conf
  if [[ -e "$f" ]] && grep -qE '^\s*DocumentRoot\s+/var/www/html\s*$' "$f"; then
    a2dissite -q 000-default.conf >/dev/null && touch "$DEFAULT_MARK" && ok "Apache default site disabled (Kelidban answers on this IP)"
  fi
}

# ---------- SSL ----------
# 0 = a valid certificate for $1 is served on 443 by this server
https_works() {
  curl -fsS -o /dev/null --max-time 10 --resolve "$1:443:127.0.0.1" "https://$1/api/verify.php?license_key=health&domain=health.test" 2>/dev/null \
  || curl -sS -o /dev/null --max-time 10 --resolve "$1:443:127.0.0.1" -w '%{http_code}' "https://$1/" 2>/dev/null | grep -q '^[1-5]'
}

# certbot for domain $1, email $2 (may be empty). Installs the 443 vhost and auto-renew.
obtain_cert() {
  local d="$1" e="$2" args=()
  need_pkg certbot python3-certbot-apache
  open_firewall
  args=(--apache -d "$d" --non-interactive --agree-tos --keep-until-expiring --no-redirect)
  if [[ -n "$e" ]]; then args+=(-m "$e"); else args+=(--register-unsafely-without-email); fi
  say "Requesting a Let's Encrypt certificate for $d"
  if ! certbot "${args[@]}"; then
    err "certbot could not get a certificate for $d."
    err "  Most common causes: the domain's DNS A record does not point to $(public_ip),"
    err "  port 80 is blocked by a firewall (also the hosting provider's panel), or the domain is behind a CDN proxy."
    err "  Log: /var/log/letsencrypt/letsencrypt.log"
    return 1
  fi
  systemctl enable --now certbot.timer >/dev/null 2>&1 || true
  if ! systemctl is-enabled --quiet certbot.timer 2>/dev/null && [[ ! -f /etc/cron.d/certbot ]]; then
    echo "17 3,15 * * * root certbot renew -q --deploy-hook 'systemctl reload apache2'" > /etc/cron.d/kelidban-certbot
    chmod 644 /etc/cron.d/kelidban-certbot
  fi
  apache_ok && systemctl reload apache2
  if https_works "$d"; then
    ok "HTTPS works for $d (auto-renew enabled)"
    return 0
  fi
  err "certbot finished but https://$d still does not answer with a valid certificate"
  return 1
}

# Point the app at a working URL: https only when a valid certificate is served.
set_base() {   # scheme host
  cfg_set base_url "$1://$2"
  if [[ "$1" == "https" ]]; then cfg_set force_https true; else cfg_set force_https false; fi
}

# If config says https but nothing serves a certificate, fix it (try certbot, else fall back to http).
repair_https() {
  local host scheme
  host="$(base_host)"; scheme="$(base_scheme)"
  [[ "$scheme" == "https" && -n "$host" ]] || return 0
  https_works "$host" && return 0
  warn "base_url is https://$host but this server has no working SSL certificate for it on port 443."
  warn "That is why the panel URL does not open (http redirects to https, and https has no certificate)."
  if ! is_ipv4 "$host" && dns_points_here "$host" && confirm "Get a free Let's Encrypt certificate for $host now?"; then
    local e="$EMAIL"; [[ -n "$e" ]] || e="$(ask "Email for the certificate (Enter to skip):" "")"
    obtain_cert "$host" "$e" && return 0
  elif ! is_ipv4 "$host" && ! dns_points_here "$host"; then
    warn "$host resolves to '$(resolve4 "$host")', this server is $(public_ip): fix the DNS A record, then: sudo kelidban ssl"
  fi
  if [[ -d "/etc/letsencrypt/live/$host" ]]; then
    warn "A certificate for $host exists in /etc/letsencrypt/live/$host but is not served. Not switching to http."
    warn "Fix: sudo kelidban ssl   (re-installs it into Apache), then: sudo kelidban check"
    return 0
  fi
  if confirm "Switch the panel to plain http://$host for now (no https redirect) so it opens?"; then
    set_base http "$host"
    warn "Panel now uses http://$host (not encrypted). Get a certificate later: sudo kelidban ssl"
  fi
}

# ---------- self-check ----------
CHECK_FAILS=0
c_ok()   { echo "  ${C_G}[OK]${C_0}   $*"; }
c_warn() { echo "  ${C_Y}[WARN]${C_0} $*"; }
c_fail() { echo "  ${C_R}[FAIL]${C_0} $*"; CHECK_FAILS=$((CHECK_FAILS + 1)); }

selfcheck() {
  CHECK_FAILS=0
  echo
  echo "${C_B}Self-check${C_0}"
  if ! installed; then c_fail "Kelidban is not installed in $TARGET"; return 1; fi
  local host scheme url purl code body own me ips
  host="$(base_host)"; scheme="$(base_scheme)"; url="$(base_url)"; purl="$(panel_url)"

  # services
  if systemctl is-active --quiet apache2; then c_ok "apache2 is running"; else c_fail "apache2 is not running -> systemctl restart apache2; journalctl -u apache2 -n 50"; fi
  systemctl is-enabled --quiet apache2 2>/dev/null || c_warn "apache2 is not enabled at boot -> systemctl enable apache2"
  if apache_ok; then c_ok "Apache config test passed"; else c_fail "Apache config test failed -> apache2ctl configtest"; fi
  if apache2ctl -M 2>/dev/null | grep -q 'php[0-9._]*_module'; then c_ok "PHP module loaded in Apache"; else c_fail "PHP is not loaded in Apache (pages would download as text) -> apt-get install libapache2-mod-php"; fi
  if [[ -e /etc/apache2/sites-enabled/kelidban.conf ]] || grep -qsF "$TARGET/public" /etc/apache2/sites-enabled/*.conf; then
    c_ok "Apache site for $TARGET/public is enabled"
  else
    c_fail "no enabled Apache site points to $TARGET/public -> run Install/Update again"
  fi

  # ports
  own="$(port_owner 80)"
  if [[ "$own" == *apache2* ]]; then c_ok "port 80 listening (apache2)"; elif [[ -n "$own" ]]; then c_fail "port 80 is taken by: $own"; else c_fail "nothing listens on port 80"; fi
  own="$(port_owner 443)"
  if [[ "$scheme" == "https" ]]; then
    if [[ "$own" == *apache2* ]]; then c_ok "port 443 listening (apache2)"; else c_fail "nothing (Apache) listens on port 443 -> sudo kelidban ssl"; fi
  fi

  # config / URL
  if [[ -z "$host" ]]; then c_fail "base_url in $TARGET/config.php has no host"; return 1; fi
  c_ok "base_url: $url   force_https: $(cfg_get force_https)"
  if [[ -n "$purl" ]]; then c_ok "panel folder exists"; else c_fail "no panel folder -> sudo kelidban new-url"; fi

  # storage
  if runuser -u "$WEB_USER" -- test -w "$TARGET/storage" 2>/dev/null; then c_ok "storage/ writable by $WEB_USER"; else c_fail "storage/ not writable by $WEB_USER -> run Update (fixes permissions)"; fi

  # local HTTP(S) requests, exactly as a browser would ask for the printed URL
  if [[ "$scheme" == "https" ]]; then
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host/" 2>/dev/null || true)"
    if [[ "$code" =~ ^[23] ]]; then
      c_ok "https://$host answers locally with a valid certificate"
    else
      code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host/" 2>/dev/null || true)"
      if [[ "$code" =~ ^[1-5] ]]; then
        c_fail "https://$host has an invalid/self-signed/mismatched certificate -> sudo kelidban ssl"
      else
        c_fail "port 443 does not speak TLS for $host (no certificate installed) while the panel forces https -> sudo kelidban ssl"
      fi
    fi
  fi
  body="$(curl -sk -L --max-time 10 --resolve "$host:80:127.0.0.1" --resolve "$host:443:127.0.0.1" "$url/" 2>/dev/null || true)"
  if [[ "$body" == *'content="kelidban"'* ]]; then c_ok "home page served by Kelidban (PHP works)"; else c_fail "home page $url/ did not return the Kelidban page -> see: sudo kelidban logs"; fi
  if [[ -n "$purl" ]]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$host:80:127.0.0.1" --resolve "$host:443:127.0.0.1" "$purl" 2>/dev/null || true)"
    if [[ "$code" == "200" || "$code" == "429" ]]; then c_ok "panel URL answers locally (HTTP $code)"; else c_fail "panel URL $purl answered HTTP ${code:-none} locally"; fi
  fi
  body="$(curl -s --max-time 10 --resolve "$host:80:127.0.0.1" "http://$host/api/verify.php?license_key=health&domain=health.test" 2>/dev/null || true)"
  if [[ "$body" == *'"code"'* ]]; then c_ok "bots' API answers (api/verify.php)"; else c_fail "api/verify.php did not answer JSON -> sudo kelidban logs"; fi

  # DNS and outside reachability
  me="$(public_ip)"
  if ! is_ipv4 "$host"; then
    ips="$(resolve4 "$host")"
    if [[ -z "$ips" ]]; then c_fail "DNS: $host does not resolve -> add an A record pointing to $me"
    elif [[ " $ips " == *" $me "* ]]; then c_ok "DNS: $host -> $me (this server)"
    else c_warn "DNS: $host -> $ips but this server's public IP looks like $me (OK only behind a CDN/proxy)"; fi
  elif [[ -n "$me" && "$host" != "$me" ]]; then
    c_warn "base_url uses IP $host but the public IP looks like $me"
  fi
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    if ufw status | grep -qE '^(80|80/tcp|Apache|Apache Full|WWW)[[:space:]].*ALLOW'; then c_ok "ufw allows port 80"; else c_fail "ufw is active and blocks port 80 -> ufw allow 80/tcp; ufw allow 443/tcp"; fi
    if [[ "$scheme" == "https" ]] && ! ufw status | grep -qE '^(443|443/tcp|Apache Full|Apache Secure|WWW Full)[[:space:]].*ALLOW'; then c_fail "ufw blocks port 443 -> ufw allow 443/tcp"; fi
  fi
  if [[ -n "$me" ]]; then
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 -H "Host: $host" "http://$me/api/verify.php?license_key=health&domain=health.test" 2>/dev/null || true)"
    if [[ "$code" =~ ^[2-4] ]]; then c_ok "reachable on public IP $me:80 (from this server)"
    else c_warn "could not reach $me:80 from the server itself (normal on some clouds). If the browser cannot open it, open ports 80/443 in the hosting provider's firewall/security group."; fi
  fi

  echo
  if [[ $CHECK_FAILS -eq 0 ]]; then ok "All checks passed"; else err "$CHECK_FAILS check(s) failed - see the hints above"; fi
  [[ $CHECK_FAILS -eq 0 ]]
}

# ---------- password ----------
# Fresh password when none is set: ask (interactive) or generate one and save it in $CRED_FILE
ensure_password() {
  local st; st="$(kb pw-state 2>/dev/null || echo none)"
  case "$st" in
    ok) return 0 ;;
    weak|legacy) warn "The panel password is short. Change it: sudo kelidban password (or menu -> Panel URL & admin password)"; return 0 ;;
  esac
  if interactive && confirm_no "No panel password yet. Type your own now? (No = generate a strong random one)"; then
    kb password </dev/tty && rm -f "$CRED_FILE" && return 0
    warn "password not set; generating a random one"
  fi
  reset_password
}
reset_password() {
  local pw; pw="$(rand_pw)"
  printf '%s\n' "$pw" | kb password-stdin >/dev/null || die "could not set the panel password"
  umask 077
  {
    echo "Kelidban panel login (generated $(date '+%Y-%m-%d %H:%M %Z'))"
    echo "Panel URL: $(panel_url)"
    echo "Password:  $pw"
  } > "$CRED_FILE"
  chmod 600 "$CRED_FILE"
  ok "New panel password set (all open sessions signed out). Saved in $CRED_FILE"
  echo "    Password: ${C_B}$pw${C_0}"
}

show_summary() {
  local purl; purl="$(panel_url)"
  echo
  echo "  ---------------------------------------------"
  echo "  Kelidban $(version_of "$TARGET") is ready."
  echo "  Panel (keep this address private):"
  echo "    ${C_B}${purl:-(none - run: sudo kelidban new-url)}${C_0}"
  echo "  Login: password only (no username)."
  if [[ -f "$CRED_FILE" ]]; then
    echo "    Password: ${C_B}$(sed -n 's/^Password: *//p' "$CRED_FILE")${C_0}   (saved in $CRED_FILE)"
  else
    echo "    Password: the one you set (forgot it? sudo kelidban reset-password)"
  fi
  echo "  Bots verify at: $(base_url)/api/verify.php"
  if [[ "$(base_scheme)" == "http" ]]; then
    echo "  ${C_Y}No SSL yet: the panel is plain HTTP.${C_0} When the domain points here: sudo kelidban ssl"
  fi
  echo "  Menu: sudo kelidban menu      Commands: sudo kelidban help"
  echo "  ---------------------------------------------"
}

# ---------- install / update ----------
do_install() {   # $1 = "update" to refuse a fresh install
  local only_update="${1:-}" SRC WORK="" VERSION MODE OLD d f hit secret host scheme ip
  # source
  if [[ -n "$ZIP" ]]; then
    [[ -f "$ZIP" ]] || die "zip not found: $ZIP"
    command -v unzip >/dev/null 2>&1 || need_pkg unzip
    WORK="$(mktemp -d)"
    # shellcheck disable=SC2064
    trap "rm -rf '$WORK'" EXIT
    unzip -q "$ZIP" -d "$WORK"
    hit="$(find "$WORK" -path '*/app/admin.php' -print -quit)"
    [[ -n "$hit" ]] || die "this zip is not a Kelidban package"
    SRC="$(cd "$(dirname "$hit")/.." && pwd)"
  elif [[ -n "$SELF_DIR" ]] && is_src "$SELF_DIR" && [[ "$(readlink -f "$SELF_DIR")" != "$(readlink -f "$TARGET" 2>/dev/null || echo "$TARGET")" ]]; then
    SRC="$SELF_DIR"
  else
    [[ "${KELIDBAN_SYNCED:-0}" == "1" && -f "$SRC_CLONE/lib/bootstrap.php" ]] || sync_src
    SRC="$SRC_CLONE"
  fi
  is_src "$SRC" || die "incomplete package in $SRC"
  [[ "$(readlink -f "$SRC")" != "$(readlink -f "$TARGET" 2>/dev/null || echo "$TARGET")" ]] || die "the source must not be the install folder; clone to $SRC_CLONE"
  VERSION="$(version_of "$SRC")"

  # what is on this server
  MODE="fresh"; OLD=""
  if is_install "$TARGET"; then
    MODE="update"
  else
    for d in "${OLD_CANDIDATES[@]}"; do
      if is_install "$d"; then OLD="$d"; break; fi
    done
    if [[ -z "$OLD" ]]; then
      while read -r root; do
        d="${root%/}"; d="${d%/public}"
        if [[ -n "$d" && "$d" != "$TARGET" ]] && is_install "$d"; then OLD="$d"; break; fi
      done < <(grep -hiE '^\s*DocumentRoot\s' /etc/apache2/sites-enabled/*.conf 2>/dev/null | awk '{print $2}' | tr -d '"' | sort -u)
    fi
    [[ -n "$OLD" ]] && MODE="migrate"
  fi
  if [[ "$only_update" == "update" && "$MODE" != "update" ]]; then
    die "Kelidban is not installed in $TARGET - choose Install instead"
  fi

  echo
  echo "  Kelidban $VERSION installer"
  echo "  ---------------------------------------------"
  case "$MODE" in
    update)  echo "  Mode:    UPDATE existing install in $TARGET (config and database kept)" ;;
    migrate) echo "  Mode:    MOVE old license server $OLD  ->  $TARGET"
             echo "           (config, database and licenses are kept; bots keep working)" ;;
    fresh)   echo "  Mode:    FRESH install into $TARGET" ;;
  esac
  echo "  Backup:  $BACKUP_DIR"
  echo
  confirm "Continue?" || die "cancelled"

  # packages
  if [[ "$MODE" == "fresh" && $NO_UPGRADE -eq 0 && "${KELIDBAN_UPDATED:-0}" != "1" ]]; then
    say "Updating the server (apt-get update && apt-get upgrade)"
    apt-get update -y >/dev/null || warn "apt-get update reported errors"
    apt-get upgrade -y >/dev/null || warn "apt-get upgrade reported errors"
  fi
  if [[ "$MODE" == "fresh" ]]; then ensure_apache; else need_pkg rsync curl iproute2; pick_php >/dev/null || need_pkg php-cli php-sqlite3; fi
  PHPBIN="$(pick_php)" || die "no PHP with pdo_sqlite (apt-get install php-cli php-sqlite3)"
  "$PHPBIN" -m 2>/dev/null | grep -qi '^mbstring$' || need_pkg php-mbstring
  say "PHP for tools: $PHPBIN"

  # backup
  mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
  for d in "$TARGET" "$OLD"; do
    if [[ -n "$d" ]] && is_install "$d"; then
      f="$BACKUP_DIR/$(basename "$d")-$TS.tgz"
      tar -czf "$f" -C "$d" . 2>/dev/null || tar -czf "$f" -C "$d" --ignore-failed-read . || warn "backup of $d incomplete"
      chmod 600 "$f"
      ok "Backup of $d: $f"
    fi
  done

  # files
  mkdir -p "$TARGET"
  if [[ "$MODE" == "migrate" ]]; then
    [[ -f "$OLD/config.php" ]] && cp -a "$OLD/config.php" "$TARGET/config.php"
    mkdir -p "$TARGET/storage"
    cp -a "$OLD/storage/." "$TARGET/storage/"
    [[ -f "$TARGET/config.php" ]] && sed -i "s#$OLD/#$TARGET/#g" "$TARGET/config.php"
    ok "Config and database copied from $OLD"
  fi
  rsync -a --delete \
    --exclude '/config.php' --exclude '/storage/' --exclude '/public/kb-*/' \
    --exclude '/.git/' --exclude '/.gitignore' --exclude '/.gitattributes' \
    "$SRC/" "$TARGET/"
  ok "Code $VERSION copied to $TARGET"

  local new_config=0
  if [[ ! -f "$TARGET/config.php" ]]; then
    cp "$TARGET/config.sample.php" "$TARGET/config.php"
    secret="$(head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-64)"
    sed -i "s#CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64CHARS_MINIMUM#$secret#" "$TARGET/config.php"
    new_config=1
  fi

  # permissions
  id "$WEB_USER" >/dev/null 2>&1 || WEB_USER="$(ps -eo user,comm | awk '/apache2|httpd|nginx/ && $1!="root" {print $1; exit}')"
  [[ -n "$WEB_USER" ]] || die "cannot find the web server user"
  mkdir -p "$TARGET/storage"
  chown -R root:root "$TARGET"
  find "$TARGET" -path "$TARGET/storage" -prune -o -type d -exec chmod 755 {} +
  find "$TARGET" -path "$TARGET/storage" -prune -o -type f -exec chmod 644 {} +
  chmod 755 "$TARGET/bin/kelidban" "$TARGET/install.sh"
  chown root:"$WEB_USER" "$TARGET/config.php"; chmod 640 "$TARGET/config.php"
  chown -R "$WEB_USER:$WEB_USER" "$TARGET/storage"
  chmod 750 "$TARGET/storage"
  find "$TARGET/storage" -type f -exec chmod 640 {} +
  chmod 755 "$(dirname "$TARGET")" 2>/dev/null || true
  ln -sf "$TARGET/bin/kelidban" /usr/local/bin/kelidban
  ok "Permissions set (code: root, database: $WEB_USER); command 'kelidban' installed"

  # address of this server: https only after a certificate really exists
  if [[ $new_config -eq 1 ]]; then
    ip="$(public_ip)"
    [[ -n "$DOMAIN" ]] || DOMAIN="$(ask "Domain for this license server (e.g. license.example.com; Enter = use IP $ip without SSL for now):" "")"
    DOMAIN="$(normalize_host "$DOMAIN")"
    if [[ -n "$DOMAIN" ]] && ! is_ipv4 "$DOMAIN" && ! valid_domain "$DOMAIN"; then die "not a valid domain: $DOMAIN"; fi
    if [[ -z "$DOMAIN" ]]; then
      [[ -n "$ip" ]] || die "cannot detect this server's IP; give --domain"
      DOMAIN="$ip"
    elif ! is_ipv4 "$DOMAIN" && ! dns_points_here "$DOMAIN"; then
      warn "$DOMAIN resolves to '$(resolve4 "$DOMAIN")', but this server's public IP is ${ip:-unknown}."
      warn "A URL with $DOMAIN would not open until its DNS A record points here."
      if [[ -n "$ip" ]] && confirm "Use http://$ip for the panel for now (switch to the domain later with: sudo kelidban ssl)?"; then
        DOMAIN="$ip"
      fi
    fi
    set_base http "$DOMAIN"
    ok "config.php created (base_url http://$DOMAIN until SSL is set up)"
  fi
  host="$(base_host)"; scheme="$(base_scheme)"
  [[ -n "$host" ]] || die "base_url in $TARGET/config.php has no host"

  # database migrations + private panel address
  kb migrate

  # web server
  local EDITED=()
  if [[ "$MODE" == "migrate" ]]; then
    while read -r f; do
      [[ -n "$f" ]] || continue
      local real; real="$(readlink -f "$f")"
      cp -a "$real" "$BACKUP_DIR/vhost-$(basename "$real").$TS"
      sed -i "s#$OLD/#$TARGET/#g; s#$OLD\b#$TARGET#g" "$real"
      EDITED+=("$real")
      ok "Web config updated: $real"
    done < <(grep -lF "$OLD" /etc/apache2/sites-available/*.conf 2>/dev/null || true)
    [[ ${#EDITED[@]} -gt 0 ]] || warn "no Apache site file pointed to $OLD"
    if ! apache_ok; then
      for f in "${EDITED[@]}"; do cp -a "$BACKUP_DIR/vhost-$(basename "$f").$TS" "$f"; done
      die "Apache config test failed; web config restored, nothing switched. Details: apache2ctl configtest"
    fi
    systemctl reload apache2
  elif [[ "$MODE" == "fresh" ]] || own_vhost; then
    [[ "$MODE" == "fresh" ]] || ensure_apache
    write_vhost "$host" "$(public_ip)"
    disable_stock_default
    open_firewall
    restart_apache
  else
    systemctl is-active --quiet apache2 || systemctl start apache2 || warn "apache2 did not start"
    apache_ok && systemctl reload apache2
  fi

  # SSL on a fresh install with a real domain
  if [[ "$MODE" == "fresh" && "$scheme" == "http" ]] && ! is_ipv4 "$host"; then
    if dns_points_here "$host" || confirm_no "$host does not resolve to $(public_ip) yet. Try SSL anyway?"; then
      [[ -n "$EMAIL" ]] || EMAIL="$(ask "Email for the SSL certificate (Enter to skip):" "")"
      if obtain_cert "$host" "$EMAIL"; then
        set_base https "$host"
      else
        warn "Continuing without SSL: panel uses http://$host. Retry later: sudo kelidban ssl"
      fi
    else
      warn "DNS for $host does not point here yet ($(resolve4 "$host")). Using http for now; after fixing DNS run: sudo kelidban ssl"
    fi
  fi
  # existing installs: https configured but no certificate -> fix or fall back
  repair_https

  # cron
  cat > /etc/cron.d/kelidban <<CRON
# Kelidban: health check every 15 min, database backup every night (kept 30 days)
*/15 * * * * ${WEB_USER} ${PHPBIN} ${TARGET}/cron/health_check.php >/dev/null 2>&1
30 3 * * * root /usr/local/bin/kelidban backup >/dev/null 2>&1 && find ${BACKUP_DIR} -name 'licenses_*.sqlite' -mtime +30 -delete
CRON
  chmod 644 /etc/cron.d/kelidban
  if [[ -n "$OLD" ]] && crontab -l 2>/dev/null | grep -qF "$OLD"; then
    crontab -l > "$BACKUP_DIR/crontab.$TS"
    crontab -l | sed "s#$OLD#$TARGET#g" | crontab -
    ok "root crontab paths updated"
  fi
  ok "Cron: /etc/cron.d/kelidban"

  # migrate: switch only if the site answers from the new folder
  if [[ "$MODE" == "migrate" ]]; then
    local body
    body="$(curl -sk -L --max-time 15 --resolve "$host:80:127.0.0.1" --resolve "$host:443:127.0.0.1" "$(base_url)/" 2>/dev/null || true)"
    if [[ "$body" == *'content="kelidban"'* ]]; then
      mv "$OLD" "$OLD.old-$TS"; chmod 700 "$OLD.old-$TS"
      ok "Old folder renamed to $OLD.old-$TS (also in $BACKUP_DIR); delete it after a few days"
    else
      for f in "${EDITED[@]}"; do cp -a "$BACKUP_DIR/vhost-$(basename "$f").$TS" "$f"; done
      if apache_ok; then systemctl reload apache2 || true; fi
      die "the site did not answer from $TARGET; web config restored to $OLD, nothing lost. Backup: $BACKUP_DIR"
    fi
  fi

  ensure_password
  selfcheck || warn "Fix the failed checks above, then run: sudo kelidban check"
  show_summary
}

do_update() {
  # get the newest code first, then continue with the new copy of this script
  if [[ -z "$ZIP" && "${KELIDBAN_SYNCED:-0}" != "1" ]]; then
    sync_src
    local args=(update --dir "$TARGET" --branch "$BRANCH")
    [[ $ASSUME_YES -eq 1 ]] && args+=(-y)
    [[ -n "$EMAIL" ]] && args+=(--email "$EMAIL")
    exec env KELIDBAN_SYNCED=1 bash "$SRC_CLONE/install.sh" "${args[@]}"
  fi
  do_install update
  systemctl restart apache2 || warn "apache2 restart failed"
}

# ---------- SSL menu ----------
do_ssl() {
  need_installed
  local cur d e
  cur="$(base_host)"
  is_ipv4 "$cur" && cur=""
  echo
  echo "  SSL certificate (Let's Encrypt)"
  echo "  1) Obtain / install a certificate (sets up HTTPS on port 443)"
  echo "  2) Renew certificates now"
  echo "  3) Show certificates"
  echo "  4) Back"
  local c="1"; interactive && c="$(ask "Choose:" "1")"
  case "$c" in
    2) do_renew; return ;;
    3) certbot certificates || true; return ;;
    4) return ;;
  esac
  d="$DOMAIN"; [[ -n "$d" ]] || d="$(ask "Domain (its DNS A record must point to $(public_ip)):" "$cur")"
  d="$(normalize_host "$d")"
  [[ -n "$d" ]] || die "a domain is required for SSL (an IP address cannot get a Let's Encrypt certificate)"
  is_ipv4 "$d" && die "Let's Encrypt does not issue certificates for IP addresses; use a domain"
  valid_domain "$d" || die "not a valid domain: $d"
  if ! dns_points_here "$d"; then
    warn "$d resolves to '$(resolve4 "$d")' but this server is $(public_ip)."
    confirm_no "Try anyway (e.g. DNS just changed)?" || die "fix the DNS A record of $d first"
  fi
  e="$EMAIL"; [[ -n "$e" ]] || e="$(ask "Email for expiry notices (Enter to skip):" "")"
  ensure_apache
  if own_vhost; then
    write_vhost "$d" "$(public_ip)"
  elif ! grep -qsE "ServerName\s+$d\s*$" /etc/apache2/sites-enabled/*.conf; then
    write_vhost "$d" "$(public_ip)"
  fi
  open_firewall
  restart_apache
  if [[ "$(base_host)" != "$d" ]]; then
    warn "The server address changes from $(base_url) to https://$d - bots must use the new domain."
    confirm "Continue?" || die "cancelled"
  fi
  if obtain_cert "$d" "$e"; then
    set_base https "$d"
    ok "Panel and API now use https://$d (http pages redirect to https; the bots' API is not redirected)"
  else
    if [[ "$(base_scheme)" == "https" ]] && ! https_works "$(base_host)"; then
      set_base http "$(base_host)"
      warn "No certificate: panel switched to http://$(base_host) so it keeps working"
    fi
    selfcheck || true
    return 1
  fi
  selfcheck || true
  show_summary
}

do_renew() {
  need_pkg certbot
  certbot renew --deploy-hook "systemctl reload apache2" || die "certbot renew failed (see /var/log/letsencrypt/letsencrypt.log)"
  systemctl enable --now certbot.timer >/dev/null 2>&1 || true
  ok "Renewal done; automatic renewal: $(systemctl is-enabled certbot.timer 2>/dev/null || echo 'cron')"
}

# ---------- other actions ----------
do_status() {
  need_installed
  echo
  kb status || true
  echo "services      apache2: $(systemctl is-active apache2 2>/dev/null || true), cron: $(systemctl is-active cron 2>/dev/null || true)"
  local h c; h="$(base_host)"; c="/etc/letsencrypt/live/$h/cert.pem"
  if [[ -f "$c" ]]; then echo "certificate   $(openssl x509 -enddate -noout -in "$c" 2>/dev/null | sed 's/notAfter=/expires /')"; else echo "certificate   none for $h"; fi
  echo "listening     80: $(port_owner 80)  443: $(port_owner 443)"
  echo "public IP     $(public_ip)"
  selfcheck || true
}

do_creds() {
  need_installed
  echo
  echo "  Panel URL: ${C_B}$(panel_url)${C_0}"
  echo "  Login:     password only (no username)"
  echo "  Password:  $(kb pw-state 2>/dev/null || echo unknown) (stored hashed; it cannot be read back)"
  [[ -f "$CRED_FILE" ]] && echo "  Last generated password: $(sed -n 's/^Password: *//p' "$CRED_FILE")  ($CRED_FILE)"
  interactive || return 0
  echo
  echo "  1) Generate a new random password"
  echo "  2) Type a new password"
  echo "  3) New panel URL (old one stops working)"
  echo "  4) Unlock login (after too many wrong passwords)"
  echo "  5) Back"
  case "$(ask "Choose:" "5")" in
    1) reset_password ;;
    2) kb password </dev/tty && rm -f "$CRED_FILE" ;;
    3) confirm_no "Really change the panel URL?" && kb new-url ;;
    4) kb unlock ;;
  esac
  return 0
}

do_restart() {
  local s
  for s in apache2 cron; do
    if systemctl restart "$s"; then ok "$s restarted"; else err "$s failed to restart (journalctl -u $s -n 50)"; fi
  done
  for s in $(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
    if systemctl restart "$s"; then ok "$s restarted"; else err "$s failed to restart"; fi
  done
}

do_logs() {
  local c="1"
  if interactive; then
    echo
    echo "  1) Kelidban web errors (last 100 lines)"
    echo "  2) Kelidban web access (last 50 lines)"
    echo "  3) Apache service journal"
    echo "  4) Let's Encrypt / certbot log"
    echo "  5) Follow web errors live (Ctrl+C to stop)"
    echo "  6) Back"
    c="$(ask "Choose:" "1")"
  fi
  case "$c" in
    1) tail -n 100 /var/log/apache2/kelidban-error.log 2>/dev/null || tail -n 100 /var/log/apache2/error.log ;;
    2) tail -n 50 /var/log/apache2/kelidban-access.log 2>/dev/null || tail -n 50 /var/log/apache2/access.log ;;
    3) journalctl -u apache2 -n 100 --no-pager ;;
    4) tail -n 100 /var/log/letsencrypt/letsencrypt.log ;;
    5) trap ':' INT; tail -f /var/log/apache2/kelidban-error.log /var/log/apache2/error.log || true; trap - INT ;;
  esac
  return 0
}

do_backup() {
  need_installed
  mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
  kb backup "$BACKUP_DIR"
  local f="$BACKUP_DIR/kelidban-full-$TS.tgz"
  tar -czf "$f" -C "$TARGET" config.php storage 2>/dev/null || tar -czf "$f" -C "$TARGET" --ignore-failed-read config.php storage
  chmod 600 "$f"
  ok "Config + storage: $f"
}

do_restore() {
  need_installed
  local files=() f i n pick db
  mapfile -t files < <(ls -1t "$BACKUP_DIR"/licenses_*.sqlite 2>/dev/null || true)
  [[ ${#files[@]} -gt 0 ]] || die "no database backups in $BACKUP_DIR"
  echo
  n=${#files[@]}; [[ $n -gt 20 ]] && n=20
  for ((i = 0; i < n; i++)); do echo "  $((i + 1))) ${files[$i]##*/}  ($(du -h "${files[$i]}" | cut -f1))"; done
  pick="$(ask "Restore which backup (number, Enter = cancel):" "")"
  [[ "$pick" =~ ^[0-9]+$ && $pick -ge 1 && $pick -le $n ]] || { say "cancelled"; return 0; }
  f="${files[$((pick - 1))]}"
  # shellcheck disable=SC2016
  "$(pick_php)" -r '$p=new PDO("sqlite:".$argv[1]); $p->query("SELECT COUNT(*) FROM licenses")->fetchColumn();' "$f" 2>/dev/null || die "$f is not a valid Kelidban database"
  confirm_no "Replace the current database with ${f##*/}? (the current one is backed up first)" || { say "cancelled"; return 0; }
  kb backup "$BACKUP_DIR" >/dev/null && ok "current database backed up"
  db="$(kb config-get db_path 2>/dev/null || true)"
  [[ -n "$db" ]] || db="$TARGET/storage/licenses.sqlite"
  systemctl stop apache2
  cp "$f" "$db"
  rm -f "$db-wal" "$db-shm"
  chown "$WEB_USER:$WEB_USER" "$db"; chmod 640 "$db"
  systemctl start apache2
  ok "Database restored from ${f##*/}"
}

do_uninstall() {
  need_installed
  local host; host="$(base_host)"
  echo
  warn "This removes Kelidban from $TARGET, its Apache site and cron jobs."
  warn "A backup of the database and config.php is saved in $BACKUP_DIR first. Packages (Apache, PHP) are kept."
  interactive || die "uninstall needs a terminal to confirm"
  [[ "$(ask "Type 'uninstall' to confirm:" "")" == "uninstall" ]] || { say "cancelled"; return 0; }
  do_backup
  a2dissite -q kelidban.conf >/dev/null 2>&1 || true
  a2dissite -q kelidban-le-ssl.conf >/dev/null 2>&1 || true
  rm -f "$VHOST" "$VHOST_SSL" /etc/cron.d/kelidban /etc/cron.d/kelidban-certbot
  if [[ -f "$DEFAULT_MARK" ]]; then a2ensite -q 000-default.conf >/dev/null 2>&1 || true; rm -f "$DEFAULT_MARK"; fi
  if apache_ok; then systemctl reload apache2 || true; fi
  [[ "$(readlink -f /usr/local/bin/kelidban 2>/dev/null)" == "$TARGET/bin/kelidban" ]] && rm -f /usr/local/bin/kelidban
  if is_install "$TARGET" && [[ "$TARGET" != "/" && "$TARGET" != "/home" ]]; then rm -rf --one-file-system "$TARGET"; fi
  rm -f "$CRED_FILE"
  if [[ -n "$host" && -d "/etc/letsencrypt/live/$host" ]] && confirm_no "Also delete the SSL certificate for $host?"; then
    certbot delete --cert-name "$host" --non-interactive || true
  fi
  if [[ -d "$SRC_CLONE" ]] && confirm_no "Also delete the source code in $SRC_CLONE?"; then rm -rf "$SRC_CLONE"; fi
  ok "Kelidban removed. Backups kept in $BACKUP_DIR"
}

do_help() {
  cat <<HELP

  Kelidban management - help
  ---------------------------------------------
  Install     Installs Apache + PHP, copies the app to $TARGET, creates config.php,
              the private panel address and a panel password, then runs a self-check.
              With a domain that points to this server it also gets an SSL certificate.
              Without one, the panel uses http:// until you run "SSL".
  Update      Pulls the latest code into $SRC_CLONE, backs up, updates the code,
              runs database migrations, keeps config.php, the database and the panel
              address, restarts Apache and runs the self-check.
  SSL         Gets a free Let's Encrypt certificate for your domain (port 443),
              switches the panel to https and enables automatic renewal.
  Status      Versions, services, ports, certificate and a full self-check.
  Panel URL   Shows the private panel address; reset or change the password.

  Command line (no menu):
    sudo kelidban menu | update | ssl | check | status | url | reset-password
    sudo kelidban password | new-url | unlock | backup | restore | logs | restart
    sudo bash $SRC_CLONE/install.sh install -y --domain license.example.com --email you@example.com

  Panel does not open?  sudo kelidban check   (each failed line says how to fix it)
  Also open TCP ports 80 and 443 in your hosting provider's firewall, if it has one.
HELP
}

menu() {
  local c st rc
  while true; do
    echo
    echo "  ${C_B}Kelidban management${C_0}  ($TARGET)"
    if installed; then
      st="installed $(version_of "$TARGET") - $(panel_url)"
    else
      st="not installed"
    fi
    echo "  Status: $st"
    echo "  ---------------------------------------------"
    echo "   1) Install"
    echo "   2) Update"
    echo "   3) SSL certificate (obtain / renew)"
    echo "   4) Help"
    echo "   5) Exit"
    echo "   6) Status & self-check"
    echo "   7) Panel URL & admin password"
    echo "   8) Restart services"
    echo "   9) View logs"
    echo "  10) Backup / restore database"
    echo "  11) Uninstall"
    c="$(ask "Choose [1-11]:" "")"
    set +e
    case "$c" in
      1) ( set -e; do_install ) ;;
      2) ( set -e; do_update ) ;;
      3) ( set -e; do_ssl ) ;;
      4) do_help ;;
      5|q|exit) set -e; return 0 ;;
      6) ( set -e; do_status ) ;;
      7) ( set -e; do_creds ) ;;
      8) ( set -e; do_restart ) ;;
      9) ( set -e; do_logs ) ;;
      10) ( set -e
            echo "  1) Backup now   2) Restore a backup   3) Back"
            case "$(ask "Choose:" "1")" in 1) do_backup ;; 2) do_restore ;; esac ) ;;
      11) ( set -e; do_uninstall ) ;;
      *) warn "unknown choice: $c" ;;
    esac
    rc=$?
    set -e
    [[ $rc -eq 0 ]] || warn "the action stopped with an error (code $rc); see the messages above"
    pause
  done
}

# ---------- main ----------
if [[ -z "$ACTION" ]]; then
  if [[ -n "$ZIP" || -n "$DOMAIN" || $ASSUME_YES -eq 1 ]] || ! interactive; then ACTION="install"; else ACTION="menu"; fi
fi
case "$ACTION" in
  menu) interactive || die "the menu needs a terminal; use an action, e.g.: sudo bash install.sh install -y --domain example.com"; menu ;;
  install) do_install ;;
  update) do_update ;;
  ssl) do_ssl ;;
  renew) do_renew ;;
  check) need_installed; selfcheck ;;
  status) do_status ;;
  url|creds) do_creds ;;
  reset-password) need_installed; reset_password; echo "    Panel URL: $(panel_url)" ;;
  restart) do_restart ;;
  logs) do_logs ;;
  backup) do_backup ;;
  restore) do_restore ;;
  uninstall) do_uninstall ;;
  help) do_help ;;
esac
