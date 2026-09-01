# Connect Coolify (Webkahost PaaS)

Coolify is the Git / Docker / database half of Webkahost. PNLCS bills the
customer; Coolify builds, stores and terminates the resource. See the
[full architecture](../architecture/webkahost.md).

## One-command VPS (SaaS)

On a fresh Ubuntu 22.04/24.04 VPS (root):

```bash
export WEBKAHOST_DOMAIN=billing.example.com
export WEBKAHOST_COOLIFY_DOMAIN=deploy.example.com
curl -fsSL https://raw.githubusercontent.com/ShubhamTuts/pnlcs/main/scripts/install-webkahost-saas.sh | bash
```

The script installs Docker, Coolify (which **owns public 80/443** for
customer apps and Let's Encrypt), PHP 8.4, MariaDB, a loopback Caddy on
`127.0.0.1:8088` for PNLCS, this repository, then:

```bash
php artisan webkahost:brand
php artisan webkahost:saas --catalog
php artisan optimize
```

It also writes `deploy/coolify-proxy/webkahost-billing.yaml` into Coolify's
proxy so `WEBKAHOST_DOMAIN` gets a certificate and forwards to billing.
A cron entry runs `artisan schedule:run` every minute (invoices, suspend,
SSL polls, queues).

After Coolify is up, create an API token and:

```bash
php artisan webkahost:saas --connect \
  --url=https://deploy.example.com \
  --token=YOUR_COOLIFY_TOKEN \
  --server-uuid=OPTIONAL_DESTINATION_UUID
```

`--dry-run` prints the plan and writes nothing.

## Add the server by hand

**Configuration → Servers → Add Server**, type **Coolify (Webkahost PaaS)**.

| Field | What to paste |
|---|---|
| Hostname | Coolify dashboard host |
| Port | `8000`, or `443` if Coolify is behind TLS |
| Access Hash | Coolify API token (**Keys → API tokens**) |
| Username | Optional destination **server UUID**. Empty = first Coolify server |

Press **Test Connection**.

## Products

`php artisan webkahost:saas --catalog` creates three groups:

- **Apps** — WordPress, Node.js, Next.js, static
- **Databases** — PostgreSQL, MySQL, MariaDB, MongoDB, Redis, ClickHouse
- **One-click** — n8n, Ghost, MinIO, Umami, Plausible, NocoDB, Grafana

Or create a product yourself: **Server module** Coolify, pick a **Package**.
Auto-setup **On payment**. Git kinds need a public HTTPS repository.

## SSL

Customer apps: Traefik + Let's Encrypt on Coolify. The customer attaches a
hostname on **Git & deploy** (`is_force_https_enabled`). Billing host: Caddy
in the installer script.

## What the customer sees

- Live URL, UUID, redeploy
- Git repo + branch (apps)
- Domain + TLS form
- Env vars (Git apps)
- Connection host/user/db (managed databases)
- BYOK + Agent under **AI Credits**
