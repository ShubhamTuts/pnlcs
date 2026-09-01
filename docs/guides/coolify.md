# Connect Coolify (Webkahost PaaS)

Coolify is the Git / Docker half of Webkahost. PNLCS bills the customer;
Coolify builds and runs the app. See the [full architecture](../architecture/webkahost.md).

## Add the server

**Configuration → Servers → Add Server**, type **Coolify (Webkahost PaaS)**.

| Field | What to paste |
|---|---|
| Hostname | Coolify dashboard host |
| Port | `8000`, or `443` if Coolify is behind TLS |
| Access Hash | Coolify API token (**Keys → API tokens**) |
| Username | Optional destination **server UUID**. Empty = first Coolify server |

Press **Test Connection**. A green result means the token can call `/api/v1/version` or `/api/v1/servers`.

## Products

Create a product, set **Server module** to Coolify, and pick a **Package**:

- **WordPress (one-click)** — no Git URL needed
- **Node.js / Next.js / static / any Git** — set **Git repository (HTTPS)** on the product, or let the customer (or the Agent) set it later

Auto-setup should be **On payment** so nothing is built until the invoice is paid.

## What the customer sees

An active Coolify service gets a **Git & deploy** page: live URL, UUID, redeploy, and (for Git apps) repository + branch.

The **Webkahost Agent** can deploy WordPress or a public Git repo from a sentence, but only onto a Coolify plan this customer already owns.
