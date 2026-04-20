# Onboarding — up and running in 15 minutes

Welcome to Gail. This guide gets you from a fresh clone to a working chat window in 15 minutes, assuming you have PHP, Node, and Git already installed.

---

## Minute 0–3: Clone and install

```bash
git clone <repo-url> gail
cd gail
composer install         # ~30s
npm install              # ~45s
```

If Composer complains about the PHP version: Gail targets PHP 8.3+. `php -v`.

---

## Minute 3–5: Environment

```bash
cp .env.example .env
php artisan key:generate
```

`.env.example` defaults to PostgreSQL (`DB_CONNECTION=pgsql`, DB name `gail`). Two options:

- **Postgres (recommended — required for document RAG):** on macOS the fastest path is [DBngin](https://dbngin.com/) — a free GUI that spins up a Postgres service in one click. Create a Postgres service in DBngin (leave the default port `5432`, user `postgres`, no password — matches `.env.example`), then:
  ```bash
  createdb gail
  php artisan migrate
  ```
  The `pgvector` extension is enabled by a migration; install it first if your Postgres doesn't already have it (`CREATE EXTENSION vector` must succeed — `brew install pgvector` or apt's `postgresql-NN-pgvector`). If `migrate` fails with `type vector does not exist`, pgvector is missing.

- **SQLite (everything except RAG works):** edit `.env` → `DB_CONNECTION=sqlite`, then
  ```bash
  touch database/database.sqlite
  php artisan migrate
  ```

You should see ~18 `DONE` lines (some are pgsql-only no-ops under SQLite).

**If you do NOT have Ollama**, edit `config/ai.php` to set `default` to another provider (e.g. `'anthropic'` with `ANTHROPIC_API_KEY=sk-ant-…` in `.env`). Each provider's `default_model` is defined in the same file. See [docs/deployment.md](deployment.md#environment-variables).

**If you DO have Ollama**, pull the two recommended local-dev models while you continue reading. The defaults in [config/ai.php](../config/ai.php) assume both are present:

```bash
ollama pull gemma4:e4b &      # chat — ~4B Gemma variant, Gail's default text model
ollama pull bge-m3:latest &   # embeddings (1024 dims) — required for project document RAG
```

You can swap in larger chat models later via `OLLAMA_TEXT_MODEL_default` / `OLLAMA_TEXT_MODEL_SMARTEST`, but keep `bge-m3:latest` as the embedding model — changing dimensions means re-ingesting every document.

---

## Minute 5–7: Build + smoke test

```bash
npm run build              # compiles Vite bundle (~3s)
php artisan test --compact # runs the full Pest suite (~1–2s, RefreshDatabase against :memory: sqlite)
```

If the tests pass, your setup is healthy. If not, see [Troubleshooting](#troubleshooting) below.

---

## Minute 7–9: Start it

Pick one of these:

### Option A — Laravel Herd (if installed)

Just visit `https://gail.test`. Herd already serves it. In a separate terminal:

```bash
npm run dev              # Vite HMR
```

### Option B — Everything via Composer

```bash
composer run dev
```

Four processes in one terminal: `php artisan serve` (port 8000), `queue:listen`, `pail` (live logs), and `npm run dev`. Visit `http://localhost:8000`.

---

## Minute 9–13: Walk the feature surface

1. **Chat** — type "Hi, who are you?" and watch the SSE stream. You should see a `status → text_delta → conversation → DONE` sequence in the network tab.
2. **Tool routing** — "What's the weather in Brooklyn tonight?" should trigger `CurrentLocation` → `CurrentDateTime` → `Weather` → `WebSearch` (the chaining rule in [ChatAgent::basePrompt()](../app/Ai/Agents/ChatAgent.php)).
3. **Projects** — create a project with a custom system prompt. New conversations in that project inherit the prompt via `ProjectContext`.
4. **Documents / RAG** (Postgres only) — inside a project, upload a PDF. Watch the `status` column transition `pending → processing → ready` (poll `/projects/{id}/documents`). Then ask a question the doc answers — the assistant should call `SearchProjectDocuments` and cite `[Source: filename, section N]`.
5. **Branch** — hover a message, click the branch icon. Creates a new conversation at that point.
6. **Regenerate** — click regenerate on an assistant message. The new reply has `variant_of` set to the prior reply's id.
7. **Voice** — hold the mic button to record; audio is POSTed to `/transcribe` and the transcript is inserted into the composer.
8. **Export** — open a conversation menu → Export Markdown. Downloads a `{slug}.md` file.
9. **Analytics** — visit `/analytics`. You should see your usage populate (may take a minute — results are cached for 5 minutes).
10. **Refresh** — reload the page. The chat history comes back from `GET /conversations/{id}/messages` with the same shape as the live stream — tool call blocks included.

---

## Minute 13–15: Orient yourself in the code

Three files are worth skimming in order:

1. **[app/Ai/Agents/ChatAgent.php](../app/Ai/Agents/ChatAgent.php)** — the entire agent. 116 lines. Notice how little it does: assemble a prompt, expose tagged tools, delegate to `laravel/ai`.

2. **[app/Providers/AiServiceProvider.php](../app/Providers/AiServiceProvider.php)** — tag-based registration. This is where you add new tools or context providers.

3. **[resources/js/lib/chat-state.ts](../resources/js/lib/chat-state.ts)** — the frontend reducer. Every SSE event goes through `applyChatStreamEvent`. Unknown types are dropped. This is where chat state lives.

Then browse:

- [docs/architecture.md](architecture.md) — how the pieces fit together
- [docs/development.md](development.md) — daily workflow + how to add a tool
- [docs/api.md](api.md) — HTTP endpoint reference
- [docs/data-model.md](data-model.md) — schema and lifecycle

---

## Troubleshooting

**`composer install` fails with a PHP version error**
Gail requires PHP 8.3+. Check with `php -v`. If you have Herd, `herd php:list`.

**`php artisan migrate` says "no such table: sessions"**
You skipped `php artisan key:generate` or the `.env` file is missing. Redo the environment step.

**Tests all fail with "SQLSTATE: no such table"**
`tests/Pest.php` uses `RefreshDatabase` with an in-memory SQLite db — it should work out of the box. If it doesn't, check `phpunit.xml` has `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>`.

**Chat stream hangs on "thinking…"**
Your LLM provider isn't responding. If using Ollama: is `ollama serve` running? Is the model pulled? (`ollama list`). Try the model directly: `curl http://localhost:11434/api/tags`.

**`php artisan migrate` fails with "type vector does not exist"**
The pgvector extension isn't installed for your Postgres. Install it (`brew install pgvector`, or `apt install postgresql-NN-pgvector`) and re-run. Or switch to SQLite in `.env` — the pgvector migration is a no-op there.

**Uploaded documents stay `pending` forever**
The queue worker isn't running. `composer run dev` starts one; otherwise run `php artisan queue:listen` in a separate terminal. Check `storage/logs/laravel.log` on the `ai` channel for job failures.

**`SearchProjectDocuments` always says "no indexed documents"**
Either the project has no `ready` documents, or you're on SQLite (no `embedding` column). Confirm with `php artisan db:table document_chunks` and `SELECT status, chunk_count FROM documents;`.

**"Used SomeTool" instead of a human label**
The tool isn't implementing `App\Ai\Contracts\DisplayableTool`. Run `php artisan test --compact --filter=ToolRegistryTest` — it should fail with a clear error.

**Tool call history disappears after refresh**
Check that `ConversationMessage::toChatUiArray()` returns `tool_calls` and `tool_results` correctly. See [docs/architecture.md](architecture.md#3-data-flow--a-single-chat-turn).

**Larastan complains about an error I didn't introduce**
Check `phpstan-baseline.neon` — there are 5 pre-existing entries from Eloquent generics debt. Don't add to the baseline; fix root causes or ignore at the source with a proper `@return HasMany<T, $this>` docblock.

**1Password signing fails on commit**
Unlock 1Password, or disable commit signing locally:
```bash
git config commit.gpgsign false
```

---

## Glossary

- **Agent** — an instance of `ChatAgent` that bundles system prompt + tools + history
- **Tool** — a class under `app/Ai/Tools/` that the LLM can call
- **Context provider** — a class under `app/Ai/Context/` that appends text to the system prompt
- **Action** — a single-responsibility class under `app/Actions/` that the controller delegates to
- **Wayfinder** — the package that auto-generates TypeScript route helpers from PHP routes
- **Inertia shared prop** — data injected into every Inertia page by `HandleInertiaRequests`

Welcome aboard. When in doubt, grep for the symbol and read the test — every feature has one.
