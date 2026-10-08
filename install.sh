#!/usr/bin/env bash
# Kelidban (کلیدبان) license server — install / update / move an old license_server install.
#
#   sudo bash install.sh                      run from a git checkout (e.g. /opt/kelidban-src)
#   sudo bash install.sh /root/kelidban.zip   or from a zip of this repo
#
# Options:  -y            no questions (use defaults)
#           --dir PATH    install path (default /home/kelidban)
#           --domain HOST domain for a fresh install (e.g. license.example.com)
#
# Never touches: config.php, storage/ (database), the private panel folder public/kb-*.
set -euo pipefail

TARGET="/home/kelidban"
DOMAIN=""
ASSUME_YES=0
ZIP=""
BACKUP_DIR="/root/kelidban-backups"
TS="$(date +%Y%m%d-%H%M%S)"
OLD_CANDIDATES=(/home/license_server /home/license-server /var/www/license_server /var/www/license-server)
WEB_USER="www-data"

say()  { echo "[*] $*"; }
ok()   { echo "[OK] $*"; }
warn() { echo "[!] $*"; }
die()  { echo "[x] $*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -y|--yes) ASSUME_YES=1 ;;
    --dir) TARGET="${2:?}"; shift ;;
    --domain) DOMAIN="${2:?}"; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *.zip) ZIP="$1" ;;
    *) die "unknown option: $1" ;;
  esac
  shift
done
TARGET="${TARGET%/}"

ask() {
  local q="$1" def="${2:-}" a=""
  if [[ $ASSUME_YES -eq 1 || ! -r /dev/tty ]]; then echo "$def"; return; fi
  read -r -p "$q " a </dev/tty || true
  echo "${a:-$def}"
}
confirm() {
  [[ $ASSUME_YES -eq 1 ]] && return 0
  local a; a="$(ask "$1 [Y/n]" y)"
  [[ "$a" =~ ^[Yy] ]]
}

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "run as root: sudo bash install.sh"
export DEBIAN_FRONTEND=noninteractive

is_src()     { [[ -f "$1/lib/bootstrap.php" && -f "$1/app/admin.php" && -f "$1/public/api/verify.php" ]]; }
is_install() { [[ -f "$1/lib/bootstrap.php" && -f "$1/public/api/verify.php" ]]; }

pick_php() {
  local p
  for p in php8.4 php8.3 php8.2 php8.1 php; do
    if command -v "$p" >/dev/null 2>&1 && "$p" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
      command -v "$p"
      return 0
    fi
  done
  return 1
}

# ---------- 1) source ----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
WORK=""
cleanup() { [[ -n "$WORK" ]] && rm -rf "$WORK"; return 0; }
trap cleanup EXIT

if [[ -n "$ZIP" ]]; then
  [[ -f "$ZIP" ]] || die "zip not found: $ZIP"
  command -v unzip >/dev/null 2>&1 || { apt-get update -y >/dev/null; apt-get install -y unzip >/dev/null; }
  WORK="$(mktemp -d)"
  unzip -q "$ZIP" -d "$WORK"
  hit="$(find "$WORK" -path '*/app/admin.php' | head -1)"
  [[ -n "$hit" ]] || die "this zip is not a Kelidban package"
  SRC="$(cd "$(dirname "$hit")/.." && pwd)"
elif is_src "$SCRIPT_DIR"; then
  SRC="$SCRIPT_DIR"
else
  die "run this from the kelidban folder, or give the zip: sudo bash install.sh /root/kelidban.zip"
fi
is_src "$SRC" || die "incomplete package in $SRC"
[[ "$(readlink -f "$SRC")" != "$(readlink -f "$TARGET" 2>/dev/null || echo "$TARGET")" ]] || die "the source must not be the install folder; clone to /opt/kelidban-src"
VERSION="$(tr -d '[:space:]' < "$SRC/VERSION")"

# ---------- 2) what is on this server ----------
MODE="fresh"
OLD=""
if is_install "$TARGET"; then
  MODE="update"
else
  for d in "${OLD_CANDIDATES[@]}"; do
    if is_install "$d"; then OLD="$d"; break; fi
  done
  if [[ -z "$OLD" ]]; then
    while read -r root; do
      p="${root%/}"; p="${p%/public}"
      if [[ -n "$p" && "$p" != "$TARGET" ]] && is_install "$p"; then OLD="$p"; break; fi
    done < <(grep -hiE '^\s*DocumentRoot\s' /etc/apache2/sites-enabled/*.conf 2>/dev/null | awk '{print $2}' | tr -d '"' | sort -u)
  fi
  [[ -n "$OLD" ]] && MODE="migrate"
fi

echo
echo "  Kelidban $VERSION installer"
echo "  ---------------------------------------------"
case "$MODE" in
  update)  echo "  Mode:    UPDATE existing install in $TARGET" ;;
  migrate) echo "  Mode:    MOVE old license server $OLD  ->  $TARGET"
           echo "           (config, database and licenses are kept; bots keep working)" ;;
  fresh)   echo "  Mode:    FRESH install into $TARGET" ;;
esac
echo "  Backup:  $BACKUP_DIR"
echo
confirm "Continue?" || die "cancelled"

# ---------- 3) packages ----------
need=()
command -v rsync >/dev/null 2>&1 || need+=(rsync)
command -v curl  >/dev/null 2>&1 || need+=(curl)
if [[ "$MODE" == "fresh" ]]; then
  command -v apache2 >/dev/null 2>&1 || need+=(apache2 libapache2-mod-php)
  command -v certbot >/dev/null 2>&1 || need+=(certbot python3-certbot-apache)
  need+=(php-curl php-mbstring)
fi
pick_php >/dev/null || need+=(php-sqlite3)
if [[ ${#need[@]} -gt 0 ]]; then
  say "Installing packages: ${need[*]}"
  apt-get update -y >/dev/null
  apt-get install -y "${need[@]}" >/dev/null
fi
PHPBIN="$(pick_php)" || die "no PHP with pdo_sqlite (apt-get install php-sqlite3)"
say "PHP for tools: $PHPBIN"

# ---------- 4) backup ----------
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
for d in "$TARGET" "$OLD"; do
  if [[ -n "$d" ]] && is_install "$d"; then
    f="$BACKUP_DIR/$(basename "$d")-$TS.tgz"
    tar -czf "$f" -C "$d" . 2>/dev/null || tar -czf "$f" -C "$d" --ignore-failed-read .
    chmod 600 "$f"
    ok "Backup of $d: $f"
  fi
done

# ---------- 5) files ----------
mkdir -p "$TARGET"
if [[ "$MODE" == "migrate" ]]; then
  [[ -f "$OLD/config.php" ]] && cp -a "$OLD/config.php" "$TARGET/config.php"
  mkdir -p "$TARGET/storage"
  cp -a "$OLD/storage/." "$TARGET/storage/"
  if [[ -f "$TARGET/config.php" ]]; then
    sed -i "s#$OLD/#$TARGET/#g" "$TARGET/config.php"
  fi
  ok "Config and database copied from $OLD"
fi

rsync -a --delete \
  --exclude '/config.php' --exclude '/storage/' --exclude '/public/kb-*/' \
  --exclude '/.git/' --exclude '/.gitignore' --exclude '/.gitattributes' \
  "$SRC/" "$TARGET/"
ok "Code $VERSION copied to $TARGET"

if [[ ! -f "$TARGET/config.php" ]]; then
  cp "$TARGET/config.sample.php" "$TARGET/config.php"
  secret="$(head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 64)"
  sed -i "s#CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64CHARS_MINIMUM#$secret#" "$TARGET/config.php"
  [[ -n "$DOMAIN" ]] || DOMAIN="$(ask "Domain for this license server (e.g. license.example.com):" "")"
  [[ -n "$DOMAIN" ]] || die "a domain is required for a fresh install (--domain)"
  sed -i "s#'base_url' => '[^']*'#'base_url' => 'https://$DOMAIN'#" "$TARGET/config.php"
  ok "config.php created"
fi
DOMAIN="$("$PHPBIN" -r '$c = require $argv[1]; echo parse_url((string) ($c["base_url"] ?? ""), PHP_URL_HOST);' "$TARGET/config.php")"
[[ -n "$DOMAIN" ]] || die "base_url in $TARGET/config.php has no host"

# ---------- 6) permissions ----------
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
ln -sf "$TARGET/bin/kelidban" /usr/local/bin/kelidban
ok "Permissions set (code: root, database: $WEB_USER); command 'kelidban' installed"

# ---------- 7) password + private panel address ----------
/usr/local/bin/kelidban migrate

# ---------- 8) web server ----------
apache_ok() { apache2ctl configtest >/dev/null 2>&1; }
for m in rewrite headers ssl; do
  [[ -e "/etc/apache2/mods-enabled/$m.load" ]] || a2enmod -q "$m" >/dev/null
done

EDITED=()
restore_vhosts() {
  local f
  for f in "${EDITED[@]}"; do cp -a "$BACKUP_DIR/vhost-$(basename "$f").$TS" "$f"; done
  apache_ok && systemctl reload apache2 || true
}

if [[ "$MODE" == "migrate" ]]; then
  while read -r f; do
    [[ -n "$f" ]] || continue
    real="$(readlink -f "$f")"
    cp -a "$real" "$BACKUP_DIR/vhost-$(basename "$real").$TS"
    sed -i "s#$OLD/#$TARGET/#g; s#$OLD\b#$TARGET#g" "$real"
    EDITED+=("$real")
    ok "Web config updated: $real"
  done < <(grep -lF "$OLD" /etc/apache2/sites-available/*.conf 2>/dev/null || true)
  [[ ${#EDITED[@]} -gt 0 ]] || warn "no Apache site file pointed to $OLD"
  if ! apache_ok; then
    restore_vhosts
    die "Apache config test failed; web config restored, nothing switched. Details: apache2ctl configtest"
  fi
  systemctl reload apache2
elif [[ "$MODE" == "fresh" ]]; then
  cat > /etc/apache2/sites-available/kelidban.conf <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${TARGET}/public
    <Directory ${TARGET}/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/kelidban-error.log
    CustomLog \${APACHE_LOG_DIR}/kelidban-access.log combined
</VirtualHost>
EOF
  a2ensite -q kelidban.conf >/dev/null
  apache_ok || die "Apache config test failed (apache2ctl configtest)"
  systemctl reload apache2
  email="$(ask "Email for the SSL certificate (Enter to skip):" "")"
  if [[ -n "$email" ]]; then
    certbot --apache -d "$DOMAIN" --redirect --non-interactive --agree-tos -m "$email" || warn "certbot failed; run it again later"
  else
    certbot --apache -d "$DOMAIN" --redirect --non-interactive --agree-tos --register-unsafely-without-email || warn "certbot failed; run it again later"
  fi
else
  apache_ok && systemctl reload apache2
fi

# ---------- 9) cron ----------
cat > /etc/cron.d/kelidban <<EOF
# Kelidban: health check every 15 min, database backup every night (kept 30 days)
*/15 * * * * ${WEB_USER} ${PHPBIN} ${TARGET}/cron/health_check.php >/dev/null 2>&1
30 3 * * * root /usr/local/bin/kelidban backup >/dev/null 2>&1 && find ${BACKUP_DIR} -name 'licenses_*.sqlite' -mtime +30 -delete
EOF
chmod 644 /etc/cron.d/kelidban
if [[ -n "$OLD" ]] && crontab -l 2>/dev/null | grep -qF "$OLD"; then
  crontab -l > "$BACKUP_DIR/crontab.$TS"
  crontab -l | sed "s#$OLD#$TARGET#g" | crontab -
  ok "root crontab paths updated"
fi
ok "Cron: /etc/cron.d/kelidban"

# ---------- 10) check ----------
probe() { curl -sk --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$1" 2>/dev/null || true; }
sleep 1
home="$(probe /)"
api="$(probe '/api/verify.php?license_key=health&domain=health.test')"
if [[ "$home" == *'content="kelidban"'* && "$api" == *'"code"'* ]]; then
  ok "https://$DOMAIN answers from $TARGET (bots' API OK)"
  if [[ "$MODE" == "migrate" ]]; then
    mv "$OLD" "$OLD.old-$TS"
    ok "Old folder renamed to $OLD.old-$TS (also in $BACKUP_DIR); delete it after a few days"
  fi
else
  if [[ "$MODE" == "migrate" ]]; then
    restore_vhosts
    die "the site did not answer from $TARGET; web config restored to $OLD, nothing lost. Backup: $BACKUP_DIR"
  fi
  warn "could not confirm https://$DOMAIN yet (DNS / SSL?). Check in a browser."
fi

# ---------- 11) done ----------
echo
if [[ $ASSUME_YES -eq 0 && -r /dev/tty ]]; then
  if ! /usr/local/bin/kelidban pw-ok >/dev/null 2>&1; then
    if confirm "Set a new, strong panel password now?"; then
      /usr/local/bin/kelidban password </dev/tty || true
    fi
  fi
fi
echo "  ---------------------------------------------"
echo "  Kelidban $VERSION is ready."
echo "  Panel (keep this address private):"
echo "    $(/usr/local/bin/kelidban url 2>/dev/null | head -1)"
echo "  Bots verify at: https://$DOMAIN/api/verify.php  (unchanged)"
echo "  Commands: sudo kelidban help"
echo "  ---------------------------------------------"
