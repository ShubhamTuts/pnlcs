#!/usr/bin/env bash
# Oneploy.dev SaaS — one-command VPS bootstrap.
#
# Installs Docker, Coolify (PaaS + Traefik TLS for customer apps),
# PHP 8.4, MariaDB, this PNLCS tree, brands Oneploy, seeds the catalog,
# and puts marketing / client / billing behind Coolify's 80/443 proxy.
#
# Usage (Ubuntu 22.04 / 24.04, as root):
#   export ONEPLOY_DOMAIN=oneploy.dev
#   export ONEPLOY_COOLIFY_DOMAIN=deploy.oneploy.dev   # optional
#   curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/pnlcs/main/scripts/install-webkahost-saas.sh | bash
#
# Hosts:
#   oneploy.dev              marketing
#   client.oneploy.dev       client portal (services, domains, Agent)
#   billing.oneploy.dev      invoices, AI credits, payments
#
# Legacy: WEBKAHOST_DOMAIN still works as a single public hostname.
set -euo pipefail

COOLIFY_DOMAIN="${ONEPLOY_COOLIFY_DOMAIN:-${WEBKAHOST_COOLIFY_DOMAIN:-}}"
APP_DIR="${ONEPLOY_APP_DIR:-${WEBKAHOST_APP_DIR:-/opt/oneploy}}"
REPO="${ONEPLOY_REPO:-${WEBKAHOST_REPO:-https://github.com/ShubhamTuts/pnlcs.git}}"
BRANCH="${ONEPLOY_BRANCH:-${WEBKAHOST_BRANCH:-main}}"
DB_NAME="${ONEPLOY_DB:-${WEBKAHOST_DB:-pnlcs}}"
DB_USER="${ONEPLOY_DB_USER:-${WEBKAHOST_DB_USER:-pnlcs}}"
DB_PASS="${ONEPLOY_DB_PASS:-${WEBKAHOST_DB_PASS:-$(openssl rand -hex 16)}}"
BILLING_PORT="${ONEPLOY_BILLING_PORT:-${WEBKAHOST_BILLING_PORT:-8088}}"

ROOT_DOMAIN="${ONEPLOY_DOMAIN:-}"
MARKETING_HOST="${ONEPLOY_MARKETING_HOST:-}"
CLIENT_HOST="${ONEPLOY_CLIENT_HOST:-}"
BILLING_HOST="${ONEPLOY_BILLING_HOST:-}"

if [[ -z "$ROOT_DOMAIN" && -n "${WEBKAHOST_DOMAIN:-}" ]]; then
  ROOT_DOMAIN="${WEBKAHOST_DOMAIN}"
fi
if [[ -z "$MARKETING_HOST" ]]; then
  MARKETING_HOST="${ROOT_DOMAIN}"
fi
if [[ -n "$ROOT_DOMAIN" ]]; then
  CLIENT_HOST="${CLIENT_HOST:-client.${ROOT_DOMAIN}}"
  BILLING_HOST="${BILLING_HOST:-billing.${ROOT_DOMAIN}}"
fi
# Single-host legacy: WEBKAHOST_DOMAIN with no ONEPLOY_* split.
if [[ -z "${ONEPLOY_DOMAIN:-}" && -n "${WEBKAHOST_DOMAIN:-}" && -z "${ONEPLOY_CLIENT_HOST:-}" ]]; then
  MARKETING_HOST="${WEBKAHOST_DOMAIN}"
  CLIENT_HOST="${WEBKAHOST_DOMAIN}"
  BILLING_HOST="${WEBKAHOST_DOMAIN}"
fi

DOMAIN="${MARKETING_HOST}"
SESSION_DOMAIN=""
if [[ "$MARKETING_HOST" == *.* && "$CLIENT_HOST" != "$MARKETING_HOST" ]]; then
  SESSION_DOMAIN=".${MARKETING_HOST}"
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  echo "Set ONEPLOY_DOMAIN=oneploy.dev (or WEBKAHOST_DOMAIN=billing.example.com)." >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq curl ca-certificates git unzip software-properties-common openssl \
  debian-keyring debian-archive-keyring apt-transport-https iproute2 gpg

echo "==> Docker"
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker

echo "==> Coolify (PaaS / proxy / Let's Encrypt for customer apps)"
if [[ ! -d /data/coolify ]]; then
  curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
fi

echo "==> PHP 8.4 + Caddy (loopback) + MariaDB"
add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
apt-get update -qq
apt-get install -y -qq php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-mysql php8.4-bcmath php8.4-intl php8.4-gd php8.4-readline mariadb-server

if ! command -v caddy >/dev/null 2>&1; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null
  apt-get update -qq
  apt-get install -y -qq caddy
fi

if [[ ! -x /usr/local/bin/composer ]]; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

PHP_BIN="$(command -v php8.4 || command -v php)"
FPM_SOCK="/run/php/php8.4-fpm.sock"
if [[ ! -S "$FPM_SOCK" ]]; then
  systemctl enable --now php8.4-fpm || true
fi

echo "==> Database"
systemctl enable --now mariadb
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

echo "==> PNLCS / Oneploy.dev"
mkdir -p "$(dirname "$APP_DIR")"
if [[ ! -d "$APP_DIR/.git" ]]; then
  git clone --branch "$BRANCH" --depth 1 "$REPO" "$APP_DIR"
else
  git -C "$APP_DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$APP_DIR" checkout "$BRANCH"
  git -C "$APP_DIR" pull --ff-only origin "$BRANCH"
fi

cd "$APP_DIR"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "==> Frontend assets (Vite)"
# Client/admin layouts use @vite. Without a manifest those pages 500 even
# though they already have inline CSS. Build if Node is available; OptionalVite
# still skips tags when the manifest is missing.
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y -qq nodejs
fi
if command -v npm >/dev/null 2>&1; then
  npm ci --no-audit --no-fund
  npm run build
  rm -rf node_modules
else
  echo "npm not found — portal still loads (inline CSS); extra Vite CSS skipped."
fi
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
"$PHP_BIN" artisan key:generate --force

# Point Laravel at this host and database.
"$PHP_BIN" -r "
\$f = '.env';
\$e = file_get_contents(\$f);
\$set = function (\$e, \$k, \$v) {
    \$v = str_replace(['\\\\', '\"'], ['\\\\\\\\', '\\\\\"'], \$v);
    if (preg_match('/^'.preg_quote(\$k, '/').'=/m', \$e)) {
        return preg_replace('/^'.preg_quote(\$k, '/').'=.*/m', \$k.'='.\$v, \$e, 1);
    }
    return \$e.PHP_EOL.\$k.'='.\$v.PHP_EOL;
};
\$e = \$set(\$e, 'APP_NAME', 'Oneploy');
\$e = \$set(\$e, 'APP_ENV', 'production');
\$e = \$set(\$e, 'APP_DEBUG', 'false');
\$e = \$set(\$e, 'APP_URL', 'https://${DOMAIN}');
\$e = \$set(\$e, 'LOG_LEVEL', 'error');
\$e = \$set(\$e, 'DB_CONNECTION', 'mysql');
\$e = \$set(\$e, 'DB_HOST', '127.0.0.1');
\$e = \$set(\$e, 'DB_DATABASE', '${DB_NAME}');
\$e = \$set(\$e, 'DB_USERNAME', '${DB_USER}');
\$e = \$set(\$e, 'DB_PASSWORD', '${DB_PASS}');
\$e = \$set(\$e, 'ONEPLOY_MARKETING_HOST', '${MARKETING_HOST}');
\$e = \$set(\$e, 'ONEPLOY_CLIENT_HOST', '${CLIENT_HOST}');
\$e = \$set(\$e, 'ONEPLOY_BILLING_HOST', '${BILLING_HOST}');
if ('${SESSION_DOMAIN}' !== '') {
    \$e = \$set(\$e, 'SESSION_DOMAIN', '${SESSION_DOMAIN}');
}
file_put_contents(\$f, \$e);
"

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan storage:link --force || true
"$PHP_BIN" artisan oneploy:brand --no-interaction
"$PHP_BIN" artisan webkahost:saas --catalog --no-interaction
"$PHP_BIN" artisan optimize

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

echo "==> Billing HTTP on 127.0.0.1:${BILLING_PORT} (Coolify proxy owns public 80/443)"
cat >/etc/caddy/Caddyfile <<CADDY
{
    auto_https off
}
:${BILLING_PORT} {
    bind 127.0.0.1
    encode gzip zstd
    root * ${APP_DIR}/public
    php_fastcgi unix/${FPM_SOCK}
    file_server
    header {
        X-Content-Type-Options nosniff
        Referrer-Policy strict-origin-when-cross-origin
        X-Frame-Options SAMEORIGIN
    }
}
CADDY
systemctl enable --now caddy php8.4-fpm mariadb
systemctl reload caddy || systemctl restart caddy

HOST_IP=$(ip -4 addr show docker0 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1 || true)
HOST_IP="${HOST_IP:-172.17.0.1}"

install_coolify_tls_route() {
  local dest="$1"
  mkdir -p "$(dirname "$dest")"
  local src="${APP_DIR}/deploy/coolify-proxy/oneploy.yaml"
  if [[ ! -f "$src" ]]; then
    src="${APP_DIR}/deploy/coolify-proxy/webkahost-billing.yaml"
  fi
  if [[ ! -f "$src" ]]; then
    echo "Missing Coolify proxy template — skip TLS route (app stays on 127.0.0.1:${BILLING_PORT})." >&2
    return 0
  fi
  sed -e "s/MARKETING_DOMAIN/${MARKETING_HOST}/g" \
      -e "s/CLIENT_DOMAIN/${CLIENT_HOST}/g" \
      -e "s/BILLING_DOMAIN/${BILLING_HOST}/g" \
      -e "s/DOCKER_HOST_IP/${HOST_IP}/g" "$src" > "$dest"
  echo "Wrote Coolify TLS route ${dest}"
}

if [[ -d /data/coolify/proxy ]]; then
  if [[ -d /data/coolify/proxy/dynamic ]]; then
    install_coolify_tls_route /data/coolify/proxy/dynamic/oneploy.yaml
  else
    install_coolify_tls_route /data/coolify/proxy/oneploy.yaml
  fi
  docker ps --format '{{.Names}}' | grep -qi coolify-proxy && docker restart coolify-proxy || true
fi

echo "==> Scheduler (invoices, suspend, SSL poll, queues)"
cat >/etc/cron.d/webkahost <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin
* * * * * www-data cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/webkahost

echo
echo "Oneploy loopback: http://127.0.0.1:${BILLING_PORT}"
echo "Marketing: https://${MARKETING_HOST}"
echo "Client portal: https://${CLIENT_HOST}"
echo "Billing portal: https://${BILLING_HOST}"
echo "Coolify UI: http://THIS_VPS:8000  (optional host ${COOLIFY_DOMAIN:-deploy.YOURDOMAIN})"
echo
echo "Point DNS A/AAAA for ${MARKETING_HOST}, ${CLIENT_HOST} and ${BILLING_HOST} here, then:"
echo "  cd ${APP_DIR}"
echo "  ${PHP_BIN} artisan oneploy:saas --connect --url=https://${COOLIFY_DOMAIN:-127.0.0.1:8000} --token=YOUR_TOKEN --catalog --brand"
echo "Database password is in ${APP_DIR}/.env (DB_PASSWORD)."
echo "Customer apps keep using Coolify's proxy on 80/443 (Let's Encrypt)."
