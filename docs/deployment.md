# Deployment

Gail is a local-first app. "Deployment" means putting it on a machine you control and running it under a process supervisor. It is **not** intended as a shared multi-tenant service without additional auth + transport hardening.

---

## 1. Target environments

| Environment | How |
|---|---|
| Local dev (host PHP) | Laravel Herd — `https://gail.test` auto-served |
| Single-user workstation | `php artisan serve` under launchd / systemd / supervisor |
| Headless home-lab | `php-fpm` + nginx/Caddy behind Tailscale or Cloudflare Tunnel |

There is no Docker image, Kubernetes manifest, or cloud template in the repo. Roll your own if you need one.

---

## 2. Production build

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan migrate --force
```

The `npm run build` step emits to `public/build/` — make sure the web server serves from `public/`.

---

## 3. Environment variables

Set these at minimum:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…                  # from php artisan key:generate
APP_URL=https://gail.your.domain

DB_CONNECTION=sqlite              # or mysql/pgsql
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
LOG_CHANNEL=stack

GAIL_ALLOW_REMOTE=false           # change ONLY if you have auth + TLS in front

# Pick ONE provider and set its key
OLLAMA_API_KEY=ollama             # dummy, required by the driver
# ANTHROPIC_API_KEY=sk-ant-…
# OPENAI_API_KEY=sk-…
```

### `GAIL_ALLOW_REMOTE`

By default Gail restricts every inbound HTTP request to loopback. If you front the app with any kind of TLS + auth layer (Tailscale, Cloudflare Zero Trust, nginx with basic auth, etc.), set `GAIL_ALLOW_REMOTE=true`. **Never** set this on a publicly-reachable port without an authentication layer in front — the app has no built-in user auth.

---

## 4. Runtime dependencies

- **PHP 8.3+** with standard extensions (`mbstring`, `openssl`, `pdo_sqlite`, `curl`, `dom`, `xml`)
- **SQLite** (file-backed) or any Laravel-supported database
- **Node 20+** at build time only — not required on the running box
- **Ollama** (optional): if using the default provider, `ollama serve` must be reachable and the chosen model must be pulled

For Ollama:

```bash
ollama pull llama3.1:8b
ollama serve                       # on :11434
```

Provider endpoints and API keys are configured in `config/ai.php` and driven from the environment.

---

## 5. Process supervision (systemd example)

```ini
# /etc/systemd/system/gail.service
[Unit]
Description=Gail chat service
After=network.target

[Service]
Type=simple
User=gail
WorkingDirectory=/opt/gail
Environment="APP_ENV=production"
EnvironmentFile=/opt/gail/.env
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Add a matching `gail-queue.service` for `php artisan queue:work` if you use the queue:

```ini
ExecStart=/usr/bin/php artisan queue:work --tries=1 --timeout=0
```

---

## 6. Upgrades

```bash
cd /opt/gail
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache view:cache
systemctl restart gail gail-queue
```

The migration set is append-only (never modified); `migrate --force` is always safe to re-run.

---

## 7. Backups

Back up two paths:

1. `database/database.sqlite` (or your external DB)
2. `storage/app/private/uploads/` — file attachments

Both are referenced by `agent_conversation_messages.attachments[].path`. Backing up one without the other leaves broken image links on refresh.

---

## 8. Monitoring

There is no built-in metrics endpoint. For a simple health signal, poll `GET /` (returns the Inertia home page with a 200 when the app + DB + provider config are reachable).

The [analytics dashboard](api.md#analytics) at `/analytics` is intentionally a human-readable surface rather than a `/metrics` endpoint. For production observability, add a `/healthz` route, hook Laravel's log channel `ai` into your log aggregator (`StreamChatResponse` writes there on stream failure), and wire Pail or Telescope for request tracing.

---

## 9. Security considerations

- `HostGuard` blocks cloud metadata IPs (`169.254.169.254`, `metadata.google.internal`) for every tool by default. Do **not** remove these from `config/gail.php`.
- `web_fetch` additionally blocks `localhost` and `127.0.0.1` because it accepts user-supplied URLs. If you loosen this, understand SSRF risk.
- Session + CSRF are the stock Laravel setup. If you expose the app remotely, add rate limiting on `POST /` (the stream endpoint) — it's currently unbounded.
- The app has no user auth in the default configuration. Add it before exposing beyond loopback.
- Commits in this repo are SSH-signed. If you fork, update `git config user.signingkey` or disable signing with `git config commit.gpgsign false`.
