#!/usr/bin/env bash
set -euo pipefail

DOMAIN=""
EMAIL=""
ADMIN_LOGIN="admin"
ADMIN_PASSWORD=""
INSTALL_DIR="/opt/sesame-portal/current"
STATE_DIR="/var/lib/sesame-portal"
NO_ACME=0
ACME_SH=0
REPAIR=0
SKIP_ADMIN_UPDATE=0
BACKUP_DIR=""
ROLLBACK_READY=0
INSTALL_COMPLETE=0
STAGING_DIR=""
NGINX_AVAILABLE="/etc/nginx/sites-available/sesame-portal.conf"
NGINX_ENABLED="/etc/nginx/sites-enabled/sesame-portal.conf"
RBT_ALLOW_FILE="/etc/nginx/snippets/sesame-portal-rbt-allow.conf"
CRON_FILE="/etc/cron.d/sesame-portal"
SUPPORT_TOOL="/usr/local/sbin/sesame-portal-update"
SUDOERS_FILE="/etc/sudoers.d/sesame-portal-update"
UPDATE_CONFIG="/etc/sesame-portal-update.conf"

usage() {
  cat <<'USAGE'
Usage:
  sudo bash scripts/install.sh --domain portal.example.com --email admin@example.com --admin-login admin --admin-password 'secret123'

Options:
  --domain <name>             Public domain for nginx server_name.
  --email <email>             ACME email for Let's Encrypt.
  --admin-login <login>       Initial admin login, default admin.
  --admin-password <password> Initial admin password, minimum 6 chars.
  --install-dir <path>        Install target, default /opt/sesame-portal/current.
  --state-dir <path>          State directory, default /var/lib/sesame-portal.
  --no-acme                   Configure HTTP only and skip certbot.
  --acme-sh                   Use existing acme.sh cert (e.g. DNS-01); skip certbot.
  --repair                    Re-apply files/nginx/cron/migrations; admin password
                              may be omitted when an existing DB is present.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --email) EMAIL="${2:-}"; shift 2 ;;
    --admin-login) ADMIN_LOGIN="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --install-dir) INSTALL_DIR="${2:-}"; shift 2 ;;
    --state-dir) STATE_DIR="${2:-}"; shift 2 ;;
    --no-acme) NO_ACME=1; shift ;;
    --acme-sh) ACME_SH=1; shift ;;
    --repair) REPAIR=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ "$(id -u)" != "0" ]]; then
  echo "installer must run as root" >&2
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  echo "--domain is required" >&2
  exit 2
fi

if [[ "$NO_ACME" == "1" && "$ACME_SH" == "1" ]]; then
  echo "use either --no-acme or --acme-sh, not both" >&2
  exit 2
fi

if [[ "$NO_ACME" != "1" && "$ACME_SH" != "1" && -z "$EMAIL" ]]; then
  echo "--email is required unless --no-acme or --acme-sh is used" >&2
  exit 2
fi

if [[ ${#ADMIN_PASSWORD} -lt 6 ]]; then
  if [[ "$REPAIR" == "1" && -f "$STATE_DIR/portal.sqlite" ]]; then
    SKIP_ADMIN_UPDATE=1
  else
    echo "--admin-password must be at least 6 characters" >&2
    exit 2
  fi
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

backup_path() {
  local path="$1"
  local label="$2"
  if [[ -e "$path" || -L "$path" ]]; then
    cp -a "$path" "$BACKUP_DIR/$label"
  fi
}

restore_path() {
  local path="$1"
  local label="$2"
  rm -rf "$path"
  if [[ -e "$BACKUP_DIR/$label" || -L "$BACKUP_DIR/$label" ]]; then
    install -d -m 0755 "$(dirname "$path")"
    cp -a "$BACKUP_DIR/$label" "$path"
  fi
}

prepare_rollback() {
  BACKUP_DIR="$(mktemp -d /tmp/sesame-portal-install.XXXXXX)"
  backup_path "$INSTALL_DIR" install_dir
  backup_path "$STATE_DIR/config.php" config_php
  backup_path "$STATE_DIR/portal.sqlite" db_sqlite
  backup_path "$STATE_DIR/portal.sqlite-wal" db_sqlite_wal
  backup_path "$STATE_DIR/portal.sqlite-shm" db_sqlite_shm
  backup_path "$NGINX_AVAILABLE" nginx_available
  backup_path "$NGINX_ENABLED" nginx_enabled
  backup_path "$CRON_FILE" cron_file
  backup_path "$SUPPORT_TOOL" support_tool
  backup_path "$SUDOERS_FILE" sudoers_file
  backup_path "$UPDATE_CONFIG" update_config
  ROLLBACK_READY=1
}

rollback_on_error() {
  local rc="$?"
  local line="${1:-unknown}"
  if [[ "$ROLLBACK_READY" != "1" || "$INSTALL_COMPLETE" == "1" ]]; then
    exit "$rc"
  fi

  set +e
  echo "SesamePortal installer failed at line $line; rolling back changed files" >&2
  [[ -n "$STAGING_DIR" ]] && rm -rf "$STAGING_DIR"
  restore_path "$INSTALL_DIR" install_dir
  restore_path "$STATE_DIR/config.php" config_php
  restore_path "$STATE_DIR/portal.sqlite" db_sqlite
  restore_path "$STATE_DIR/portal.sqlite-wal" db_sqlite_wal
  restore_path "$STATE_DIR/portal.sqlite-shm" db_sqlite_shm
  restore_path "$NGINX_AVAILABLE" nginx_available
  restore_path "$NGINX_ENABLED" nginx_enabled
  restore_path "$CRON_FILE" cron_file
  restore_path "$SUPPORT_TOOL" support_tool
  restore_path "$SUDOERS_FILE" sudoers_file
  restore_path "$UPDATE_CONFIG" update_config
  if command -v nginx >/dev/null 2>&1; then
    if nginx -t; then
      systemctl reload nginx || systemctl restart nginx || true
    else
      echo "nginx config failed after rollback; inspect $NGINX_AVAILABLE" >&2
    fi
  fi
  reload_php_fpm
  rm -rf "$BACKUP_DIR"
  exit "$rc"
}

cleanup_rollback() {
  INSTALL_COMPLETE=1
  [[ -n "$STAGING_DIR" ]] && rm -rf "$STAGING_DIR"
  [[ -n "$BACKUP_DIR" ]] && rm -rf "$BACKUP_DIR"
}

trap 'rollback_on_error $LINENO' ERR

install_packages() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
      nginx certbot php-fpm php-cli php-sqlite3 php-curl php-mbstring sqlite3 rsync sudo
  fi
}

detect_php_fpm_socket() {
  local socket
  socket="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n 1 || true)"
  if [[ -n "$socket" ]]; then
    echo "unix:$socket"
    return
  fi
  echo "127.0.0.1:9000"
}

reload_php_fpm() {
  local unit
  if ! command -v systemctl >/dev/null 2>&1; then
    return
  fi
  while read -r unit; do
    [[ -z "$unit" ]] && continue
    systemctl reload "$unit" || systemctl restart "$unit" || true
  done < <(systemctl list-units --type=service --all 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}')
}

write_config() {
  local secret
  local crypto_secret
  local scheme="https"
  if [[ "$NO_ACME" == "1" ]]; then
    scheme="http"
  fi

  install -d -m 0750 -o www-data -g www-data "$STATE_DIR"
  if [[ -f "$STATE_DIR/config.php" ]]; then
    chown www-data:www-data "$STATE_DIR/config.php"
    chmod 0640 "$STATE_DIR/config.php"
    return
  fi

  secret="$(openssl rand -hex 32)"
  crypto_secret="$(openssl rand -hex 32)"
  cat > "$STATE_DIR/config.php" <<PHP
<?php
return [
    'state_dir' => '$STATE_DIR',
    'db_path' => '$STATE_DIR/portal.sqlite',
    'db_dsn' => null,
    'db_user' => null,
    'db_password' => null,
    'app_secret' => '$secret',
    'timezone' => 'UTC',
    'locale' => 'ru',
    'base_url' => '$scheme://$DOMAIN',
    'auth_backend_path' => '/api/sesamedvr/auth',
    'rbt_authbackend' => ['clients' => []],
    'crypto_primary_key' => 'primary',
    'crypto_keys' => [
        'primary' => '$crypto_secret',
    ],
];
PHP
  chown www-data:www-data "$STATE_DIR/config.php"
  chmod 0640 "$STATE_DIR/config.php"
}

copy_release() {
  local install_parent
  install_parent="$(dirname "$INSTALL_DIR")"
  STAGING_DIR="$install_parent/.sesame-portal.staging.$$"
  rm -rf "$STAGING_DIR"
  install -d -m 0755 "$install_parent"
  install -d -m 0755 "$STAGING_DIR"
  rsync -a --delete \
    --exclude '.git/' \
    --exclude 'var/' \
    "$SOURCE_DIR/" "$STAGING_DIR/"
  chmod +x "$STAGING_DIR/bin/portal" "$STAGING_DIR/scripts/install.sh" "$STAGING_DIR/scripts/package-release.sh"
  rm -rf "$INSTALL_DIR"
  mv "$STAGING_DIR" "$INSTALL_DIR"
  STAGING_DIR=""
  chmod +x "$INSTALL_DIR/bin/portal" "$INSTALL_DIR/scripts/install.sh" "$INSTALL_DIR/scripts/package-release.sh"
}

run_portal_cli() {
  sudo -u www-data \
    SESAME_PORTAL_STATE_DIR="$STATE_DIR" \
    SESAME_PORTAL_CONFIG="$STATE_DIR/config.php" \
    php "$INSTALL_DIR/bin/portal" "$@"
}

ensure_rbt_allow_file() {
  install -d -m 0755 "$(dirname "$RBT_ALLOW_FILE")"
  if [[ ! -f "$RBT_ALLOW_FILE" ]]; then
    cat > "$RBT_ALLOW_FILE" <<'ALLOW'
# Add the source IP/CIDR of each RBT before the deny rule, then reload nginx.
# allow 192.0.2.10;
deny all;
ALLOW
    chmod 0644 "$RBT_ALLOW_FILE"
  fi
}

write_nginx_http() {
  local fpm="$1"
  ensure_rbt_allow_file
  cat > "$NGINX_AVAILABLE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $INSTALL_DIR/public;
    index index.php;

    location = /api/rbt/v1/secrets {
        include $RBT_ALLOW_FILE;
        client_max_body_size 4k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/public/index.php;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
        fastcgi_pass $fpm;
    }

    location = /api/sesamedvr/auth {
        # Query parameters contain playback tokens.
        access_log off;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/public/index.php;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
        fastcgi_pass $fpm;
    }

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass $fpm;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
    }
}
NGINX
  ln -sfn "$NGINX_AVAILABLE" "$NGINX_ENABLED"
}

write_nginx_https() {
  local fpm="$1"
  ensure_rbt_allow_file
  cat > "$NGINX_AVAILABLE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN;

    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;

    root $INSTALL_DIR/public;
    index index.php;

    location = /api/rbt/v1/secrets {
        include $RBT_ALLOW_FILE;
        client_max_body_size 4k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
        fastcgi_pass $fpm;
    }

    location = /api/sesamedvr/auth {
        # Query parameters contain playback tokens.
        access_log off;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
        fastcgi_pass $fpm;
    }

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass $fpm;
        fastcgi_param HTTPS on;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
    }
}
NGINX
}

deploy_acme_sh_cert() {
  local acme
  install -d -m 0755 "/etc/letsencrypt/live/$DOMAIN"
  acme="$(command -v acme.sh 2>/dev/null || true)"
  if [[ -z "$acme" ]]; then
    acme="/root/.acme.sh/acme.sh"
  fi
  if [[ ! -x "$acme" ]]; then
    echo "acme.sh not found; issue a certificate first or install acme.sh" >&2
    exit 1
  fi
  "$acme" --install-cert -d "$DOMAIN" \
    --key-file "/etc/letsencrypt/live/$DOMAIN/privkey.pem" \
    --fullchain-file "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" \
    --reloadcmd "systemctl reload nginx"
}

patch_base_url_https() {
  if [[ -f "$STATE_DIR/config.php" ]]; then
    sed -i "s#'base_url' => 'http://$DOMAIN'#'base_url' => 'https://$DOMAIN'#" "$STATE_DIR/config.php"
  fi
}

write_cron() {
  cat > "$CRON_FILE" <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

5 0 * * * www-data SESAME_PORTAL_STATE_DIR=$STATE_DIR SESAME_PORTAL_CONFIG=$STATE_DIR/config.php php $INSTALL_DIR/bin/portal rotate-tokens >/dev/null 2>&1
CRON
}

install_support_tools() {
  install -m 0755 "$SOURCE_DIR/scripts/sesame-portal-update.sh" "$SUPPORT_TOOL"
  cat > "$UPDATE_CONFIG" <<CONF
REPO='rosteleset/SesamePortal'
REF='main'
INSTALL_LINK='$INSTALL_DIR'
STATE_DIR='$STATE_DIR'
PHP_BIN='$PHP_BIN'
CONF
  chmod 0644 "$UPDATE_CONFIG"
  cat > "$SUDOERS_FILE" <<SUDOERS
www-data ALL=(root) NOPASSWD: $SUPPORT_TOOL
SUDOERS
  chmod 0440 "$SUDOERS_FILE"
  if command -v visudo >/dev/null 2>&1; then
    visudo -cf "$SUDOERS_FILE" >/dev/null
  fi
}

main() {
  prepare_rollback
  install_packages
  PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
  if [[ -z "$PHP_BIN" ]]; then
    echo "php not found" >&2
    exit 1
  fi

  copy_release
  write_config
  run_portal_cli migrate
  if [[ "$SKIP_ADMIN_UPDATE" == "1" ]]; then
    echo "repair mode: admin password omitted, existing admin users are unchanged"
  else
    run_portal_cli create-admin "$ADMIN_LOGIN" "$ADMIN_PASSWORD"
  fi
  write_cron
  install_support_tools

  local fpm
  fpm="$(detect_php_fpm_socket)"
  if [[ "$ACME_SH" == "1" ]]; then
    deploy_acme_sh_cert
    write_nginx_https "$fpm"
    patch_base_url_https
    nginx -t
    systemctl reload nginx || systemctl restart nginx
  elif [[ "$NO_ACME" == "1" ]]; then
    write_nginx_http "$fpm"
    nginx -t
    systemctl reload nginx || systemctl restart nginx
  else
    write_nginx_http "$fpm"
    nginx -t
    systemctl reload nginx || systemctl restart nginx
    certbot certonly --webroot -w "$INSTALL_DIR/public" -d "$DOMAIN" --agree-tos -m "$EMAIL" --non-interactive
    write_nginx_https "$fpm"
    nginx -t
    systemctl reload nginx || systemctl restart nginx
  fi
  reload_php_fpm

  cleanup_rollback
  local scheme="https"
  if [[ "$NO_ACME" == "1" ]]; then
    scheme="http"
  fi
  echo "SesamePortal installed: $scheme://$DOMAIN"
  echo "Admin login: $ADMIN_LOGIN"
  if [[ "$SKIP_ADMIN_UPDATE" == "1" ]]; then
    echo "Admin password unchanged: repair mode without --admin-password"
  else
    echo "Admin password: value passed with --admin-password"
  fi
}

main "$@"
