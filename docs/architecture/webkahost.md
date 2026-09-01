# Webkahost — end-to-end architecture

Webkahost is a **Vercel-shaped hosting company** built by composing two
open-source products you already have, plus a thin AI plane branded as
Webkahost:

| Layer | Product | Job |
|---|---|---|
| Commerce | **PNLCS** (this repo) | Shop, invoices, domains, tickets, white-label portal |
| PaaS | **Coolify** | Git push-to-deploy, Nixpacks, Traefik, Let's Encrypt, one-click WordPress |
| AI | **Webkahost Agent + Gateway** | Jobs (deploy, inspect) and metered model access |

This is **feasible**. It is not a pixel-clone of Vercel on day one. It is a
hosting business that can sell Git deploys, WordPress, Node.js, AI credits and
an agent from one branded portal.

## Feasibility

**Yes — as a composed platform.** PNLCS already bills, provisions and
white-labels. Coolify already speaks Git, Docker, WordPress templates and a
REST API. The missing pieces were a Coolify server module, an AI credit wallet,
an OpenAI-compatible gateway, and an agent that is allowed to deploy. Those
are in this repository.

What Webkahost **can** ship with this stack:

- One-click WordPress (Coolify service template, billed in PNLCS)
- One-click Node.js / Next.js / static from a public Git repo
- Git version control (repo + branch on the service, redeploy on demand)
- Customer portal branded Webkahost (`php artisan webkahost:brand`)
- AI credit packs, API keys (`wk_live_…`), usage ledger
- AI Gateway at `/api/ai/v1` (OpenAI chat-completions shape)
- Webkahost Agent: “deploy WordPress on blog.example.com”

What it **cannot** honestly claim without more work (see [Gaps](#gaps)):

- Vercel’s global edge, ISR, and serverless functions as a product
- A Hostinger-grade private-agent OS (OpenClaw / Hermes) running on every VPS
- Multi-region Anycast, image/video models, or a public model marketplace
- Per-PR preview URLs as a first-class billed SKU (Coolify can do previews;
  Webkahost does not sell them yet)

Coolify Cloud vs self-host: self-host Coolify on your own servers (Hetzner,
OVH, your rack). That is the Vercel *experience* on infrastructure you meter.
It is not Vercel’s *network*.

## How a request travels

```
Customer browser
    │
    ├─ billing.webkahost.com     PNLCS (Laravel) ── shop, invoices, agent UI
    │         │
    │         ├─ Server module: Coolify ── HTTPS Bearer ── coolify.webkahost.com
    │         │                                              │
    │         │                                         SSH + Docker
    │         │                                              │
    │         │                                         customer apps
    │         │                                         (Traefik + TLS)
    │         │
    │         └─ AI credits wallet ── Gateway /api/ai/v1 ── upstream LLM
    │
    └─ *.webkahost.app / custom domain   the app Coolify just deployed
```

PNLCS never builds the container. Coolify never sees the card. The Agent is
the only component that talks to both, and only for **this** customer.

## Products to sell

Create these in **Products** after connecting a Coolify server
(`Configuration → Servers → type Coolify`):

| Product | Server module | Package | Notes |
|---|---|---|---|
| Managed WordPress | `coolify` | `wordpress` | One-click `wordpress-with-mysql` + TLS |
| Node.js / Next.js / static | `coolify` | `nodejs` / `nextjs` / `static` | Public Git HTTPS |
| PostgreSQL / MySQL / Redis / MongoDB | `coolify` | `postgresql` etc. | Private DB, connection details in the portal |
| n8n / Ghost / MinIO | `coolify` | `n8n` / `ghost` / `minio` | One-click services |
| AI Starter / Builder / Scale | none (`AiCredits`) | — | Or **BYOK** for unlimited own-key inference |

Traditional cPanel-style hosting can still sit beside this: add a Panelica
(or cPanel) server and sell it as “Web Hosting”. Webkahost then looks like
Hostinger (shared + WordPress + AI) **and** Vercel (Git apps) in one bill.

## Channels (one SaaS, several doors)

| Channel | Who uses it | How it is created |
|---|---|---|
| Shop / order form | Customer | Catalog groups Apps, Databases, One-click |
| Client portal | Customer | Git, TLS, env, DB connection, AI credits, BYOK |
| Webkahost Agent | Customer | Natural-language deploy / SSL / usage |
| AI Gateway API | Apps | `/api/ai/v1` with `wk_live_` (BYOK skips the wallet) |
| Admin + Artisan | Operator | `webkahost:saas --connect --catalog --brand` |
| VPS installer | Operator | `scripts/install-webkahost-saas.sh` |

Payment on any of the shop SKUs calls the same Coolify module. The Agent
does not bypass billing — it only acts on a Coolify service the customer
already paid for.

## Coolify wiring

1. Install Coolify on a control node ([coolify.io](https://coolify.io)).
2. Add worker servers in Coolify (the machines that run Docker).
3. Create an API token (**Keys → API tokens**).
4. In PNLCS: **Configuration → Servers → Add Server**
   - Type: **Coolify (Webkahost PaaS)**
   - Hostname: Coolify dashboard
   - Port: `8000` (or `443` behind TLS)
   - Access Hash: the API token
   - Username (optional): destination **server UUID**
5. **Test Connection**.
6. Create products with module `coolify` and pick a package.

On payment, `CoolifyModule::create()`:

1. Ensures a Coolify project `webkahost-client-{id}`
2. Creates a WordPress/one-click **service**, a **database**, or a public Git **application**
3. Stores UUIDs on `services.module_data`
4. Marks the PNLCS service **Active** only after Coolify accepts the create
5. Git apps set `is_auto_deploy_enabled` so a push to `main` redeploys

Suspend stops the resource; terminate deletes it. The customer’s **Git &
deploy** page can change the repository (HTTPS GitHub / GitLab / Bitbucket /
Gitea only), attach a hostname + Let's Encrypt, set env vars, and redeploy.

## AI Gateway (Vercel AI Gateway analogue)

Base URL: `https://your-pnlcs/api/ai/v1`

```bash
curl https://billing.webkahost.com/api/ai/v1/chat/completions \
  -H "Authorization: Bearer wk_live_…" \
  -d '{"model":"gpt-4o-mini","messages":[{"role":"user","content":"Hello"}]}'
```

- Keys are created in **AI Credits**. Only a SHA-256 hash is stored.
- Each call reserves and then deducts credits from `ai_wallets`.
- `402` means the wallet is empty — buy a pack.
- Set `WEBKAHOST_AI_UPSTREAM_URL` + `WEBKAHOST_AI_UPSTREAM_KEY` (or the
  matching Settings rows) to forward to OpenAI, Groq, Together, or an
  OpenAI-compatible proxy. With no upstream, the gateway still returns a
  local completion so the Agent and tests work.

Credits are **not** account credit (money). Mixing them would let someone
pay invoices with leftover tokens.

**BYOK (unlimited):** the customer pastes their own OpenAI/Groq/OpenRouter
key under **AI Credits**. The Gateway still requires a `wk_live_` identity
key, but inference is forwarded to the customer's upstream and the wallet
is not charged (`usage.webkahost_byok: true`). The key is encrypted at rest.

## VPS one-command

```bash
export WEBKAHOST_DOMAIN=billing.example.com
sudo bash scripts/install-webkahost-saas.sh
php artisan webkahost:saas --connect --url=… --token=… --catalog --brand
```

Caddy on the host listens only on **127.0.0.1:8088**. Coolify's proxy keeps
**80/443** and terminates Let's Encrypt for `WEBKAHOST_DOMAIN` (see
`deploy/coolify-proxy/webkahost-billing.yaml`). Customer apps stay on that
same Coolify proxy. Details: [Connect Coolify](../guides/coolify.md).

## Webkahost Agent (Hostinger Agent analogue)

The Agent is a **job runner with a chat box**, fenced to the logged-in
client:

| Tool | What it does |
|---|---|
| `list_services` | This customer’s apps |
| `deploy_wordpress` | Create or restart WordPress on their Coolify plan |
| `deploy_git_app` | Point a Git app at a public HTTPS repo and deploy |
| `deploy_database` | PostgreSQL / MySQL / Redis / … on their DB plan |
| `deploy_oneclick` | n8n / Ghost / MinIO / Umami / … |
| `attach_domain` | Hostname + Let's Encrypt |
| `get_ai_usage` | Credit balance |

It does **not** get a shell on the host, other customers’ data, or payment
card tools. That is the Hostinger Connector idea (MCP into *your* hosting)
without handing the model the keys to the building.

## Branding

```bash
php artisan webkahost:brand
```

Sets the white-label company name to **Webkahost**, removes PNLCS/Panelica
from the customer portal, and activates the `webkahost` theme.

Point `APP_URL` at `https://billing.webkahost.com` and put Coolify on
`https://deploy.webkahost.com` (or keep it internal and only expose customer
apps).

## Recommended production shape

```
Edge DNS (Cloudflare)
  ├─ webkahost.com              marketing (PNLCS homepage / theme)
  ├─ billing.webkahost.com      PNLCS
  ├─ deploy.webkahost.com       Coolify UI (staff only, or SSO later)
  └─ *.webkahost.app            customer apps (Coolify / Traefik)

Control plane VPS     PNLCS + MySQL + Redis + queue
Coolify VPS           Coolify core
App pool (n × VPS)    Coolify destinations, one region to start
Object storage        Coolify backups (S3-compatible)
LLM upstream          OpenAI / Groq / your vLLM box
```

Start with **one region, two SKUs** (WordPress + Node Git) and AI credits.
Add Panelica shared hosting when you want email/FTP/cPanel-shaped products.
Add a second Coolify destination when the first pool is full (`max_accounts`
+ a server group).

## Gaps

| Gap | Why it matters | Honest next step |
|---|---|---|
| Preview deployments | Vercel’s PR URLs | Coolify supports them; Webkahost does not bill or show them yet |
| Private Git | Most real apps | Coolify GitHub App; store the app UUID on the server record |
| Edge / CDN | Latency vs Vercel | Cloudflare in front of Traefik, not a new runtime |
| Functions | `api/` routes | Deploy them *as* the Node app, not a separate SKU |
| Isolation | Noisy neighbours | One Coolify destination per plan tier, or dedicated VPS (Proxmox/Vultr modules already exist) |
| Agent + LLM tools | Better than regex | Point the gateway upstream (or BYOK) and add tool-calling in `WebkahostAgent` |
| MCP for IDEs | Hostinger Connector | Expose the same Coolify tools as an MCP server later |
| Image/video models | Vercel AI Gateway | Extend the catalogue and upstream map |
| Multi-tenant Coolify | API tokens are instance-wide | Always filter by `webkahost-client-{id}` projects (already done) |

## What “done” looks like for a first public beta

1. Coolify connected, test WordPress order paid → site live with TLS
2. Node.js product with a Git URL → push to `main` redeploys (Coolify webhook)
3. Customer buys Builder pack → wallet shows 5,000 credits **or** pastes BYOK
4. `wk_live_` key chats through `/api/ai/v1` and usage appears
5. Agent deploys WordPress or PostgreSQL from a sentence
6. Customer attaches `shop.example.com` → Coolify requests TLS
7. `sudo bash scripts/install-webkahost-saas.sh` then `php artisan webkahost:saas --catalog --connect`

That is a hosting company. Vercel’s remaining moat is the network and the
framework brand, not the billing form.
