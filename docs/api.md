# API reference

Gail exposes a small HTTP surface. All routes are defined in [routes/web.php](../routes/web.php). There is no `/api` prefix because the entire app runs as a single Inertia + JSON backend.

Authentication: by default the app is local-only (`GAIL_ALLOW_REMOTE=false`). No user auth is built in; Gail assumes the operator owns the machine.

---

## Chat

### `GET /` — Chat home  (`home`)

Returns the chat Inertia page with page props `conversations`, `projects`, and `agents` (from [`AgentType::options()`](../app/Ai/Agents/AgentType.php)), plus shared `toolLabels`.

### `POST /` — Stream a chat turn  (`chat.stream`)

Server-sent events response. Request body:

```json
{
  "message": "What's the weather in Brooklyn tonight?",
  "conversation_id": "c6c15d36-…",   // null for a new conversation
  "project_id": 3,                    // null for no project
  "agent": "default",                 // "default" or "limerick" (AgentType enum)
  "model": "qwen3-vl:8b",             // null uses the active provider's default_model
  "temperature": 0.7,                 // null uses provider default
  "regenerate": false,                // true emits a variant of the prior assistant reply (variant_of FK)
  "edit_message_id": "msg-…",         // optional: truncate this message + later ones first
  "file_paths": ["tmp/abc.jpg"]       // optional: attachments from /upload
}
```

Validation rules live in [StreamChatRequest](../app/Http/Requests/StreamChatRequest.php).

Response is `Content-Type: text/event-stream`. Frames (one per `data: {...}\n\n`):

| Event type | Payload | Meaning |
|---|---|---|
| `status` | `{"state": "thinking"}` | Emitted while the LLM is considering |
| `text_delta` | `{"text": "..."}` | Incremental assistant text |
| `tool_call` | `{"id", "name", "arguments"}` | A tool invocation began |
| `tool_result` | `{"id", "result"}` | That tool returned |
| `conversation` | `{"conversation_id": "..."}` | The conversation was persisted; id is now stable |
| `error` | `{"message": "..."}` | Upstream failure; stream ends |

The stream always terminates with `data: [DONE]\n\n`.

### `GET /models` — Available models  (`chat.models`)

Returns models for the provider named in `config('ai.default')`. When the default is `ollama`, models are discovered dynamically from the running daemon. For every other provider, the list comes from the provider's `available_models` entry in `config/ai.php` — returns `[]` if none is configured.

### `POST /upload` — Upload an attachment  (`chat.upload`)

Multipart form with a `file` field. Max 10 MB. Returns a JSON payload including a `path` you pass to `/` in `file_paths[]` on the next request.

### `POST /transcribe` — Audio → text  (`chat.transcribe`)

Multipart form with:

- `audio` (required, file, max 25 MB) — typically a short WebM/Opus clip recorded in the browser
- `language` (optional, string, max 10 chars) — BCP-47 tag forwarded to the provider

Routes to `Laravel\Ai\Transcription::fromUpload(...)->generate()`. Returns `{"text": "transcribed string"}`. Requires `ai.default_for_transcription` to be configured on a provider that supports transcription (e.g. OpenAI, Groq).

### `GET /uploads/{filename}` — Serve an uploaded file  (`uploads.show`)

Returns the raw file from `storage/app/private/uploads/`. Filenames are constrained to `[A-Za-z0-9._-]+`.

---

## Conversations

### `GET /conversations/search?q=…` — Full-text search  (`conversations.search`)

Returns conversations whose title OR any message body contains the query. Case-insensitive substring match. Example:

```json
[
  {"id": "abc-…", "title": "Pizza chat", "project_id": null, "updated_at": "..."},
  {"id": "def-…", "title": "…", "project_id": 3, "updated_at": "..."}
]
```

### `GET /conversations/{conversation}/messages` — Message history  (`conversations.messages`)

Returns every message in a conversation, in chronological order, shaped identically to the live SSE stream so the React reducer can hydrate on page refresh without branching logic.

```json
[
  {
    "id": "msg-…",
    "role": "user",
    "content": "What's the weather in Brooklyn?",
    "attachments": [],
    "toolCalls": [],
    "model": null,
    "usage": null,
    "cost": null,
    "created_at": "2026-04-11T12:30:00+00:00"
  },
  {
    "id": "msg-…",
    "role": "assistant",
    "content": "Mostly sunny, 72°F.",
    "attachments": [],
    "toolCalls": [
      {
        "tool_id": "call_x",
        "tool_name": "Weather",
        "arguments": {"location": "Brooklyn, NY"},
        "result": "Mostly sunny, 72°F..."
      }
    ],
    "model": "gemma4:e4b",
    "usage": {
      "prompt_tokens": 512,
      "completion_tokens": 128,
      "cache_write_input_tokens": 0,
      "cache_read_input_tokens": 0,
      "reasoning_tokens": 0
    },
    "cost": 0.0,
    "created_at": "2026-04-11T12:30:03+00:00"
  }
]
```

`model`, `usage`, and `cost` come from [`ConversationMessage::toChatUiArray`](../app/Models/ConversationMessage.php); `cost` is computed by [`ModelPricing`](../app/Support/ModelPricing.php) from the token counts and returns `null` when the model is unpriced (all Ollama models, by default).

### `GET /conversations/{conversation}/export?format=markdown|json` — Export  (`conversations.export`)

`format=markdown` (default) returns `text/markdown` with a `Content-Disposition: attachment; filename="{slug}.md"` header, rendering each message as a sectioned markdown block.

`format=json` returns a download of `{title, exported_at, messages: […]}`.

### `POST /conversations/{conversation}/branch` — Fork at a message  (`conversations.branch`)

Body: `{"message_id": "msg-…"}`. Creates a new conversation containing every message up to and including `message_id`. Returns `201` with the new conversation's metadata:

```json
{
  "id": "branch-…",
  "title": "Original title",
  "project_id": null,
  "parent_id": "source-id",
  "is_pinned": false,
  "updated_at": "..."
}
```

Errors:
- `404` if `message_id` does not belong to this conversation
- `422` if `message_id` is invalid or missing

### `PATCH /conversations/{conversation}` — Update  (`conversations.update`)

Body (all optional): `{"title": "…", "project_id": 3, "is_pinned": true}`. Validation in [UpdateConversationRequest](../app/Http/Requests/UpdateConversationRequest.php).

### `DELETE /conversations/{conversation}` — Soft delete  (`conversations.destroy`)

Sets `deleted_at`. The conversation disappears from listings but remains in the DB.

---

## Projects

### `POST /projects` — Create  (`projects.store`)

Body: `{"name": "Work assistant", "system_prompt": "You are a focused…"}`. Returns the new project.

### `PATCH /projects/{project}` — Update  (`projects.update`)

Body: `{"name": …, "system_prompt": …}`. Both optional.

### `DELETE /projects/{project}` — Soft delete  (`projects.destroy`)

---

## Project documents (RAG)

### `GET /projects/{project}/documents` — List  (`documents.index`)

Returns `id`, `name`, `status` (`pending` | `processing` | `ready` | `failed`), `chunk_count`, `size`, `mime_type`, `created_at` for every document in the project, newest first.

### `POST /projects/{project}/documents` — Upload  (`documents.store`)

Multipart form with a `file` field. Validation lives in [StoreDocumentRequest](../app/Http/Requests/StoreDocumentRequest.php). The controller stores the file at `documents/{project_id}/…` on the `local` disk, creates the `documents` row with `status=pending`, and dispatches [ProcessDocument](../app/Jobs/ProcessDocument.php) to the queue.

Response `201`:

```json
{ "id": 42, "name": "handbook.pdf", "status": "pending", "size": 184322 }
```

Clients should poll `GET /projects/{project}/documents` until `status === "ready"` (or `"failed"`). The job runs with `$tries = 1` and a 5-minute timeout.

### `DELETE /projects/{project}/documents/{document}` — Delete  (`documents.destroy`)

Deletes the file from the `local` disk and removes the row (cascade-deletes `document_chunks`). Returns `204`. Returns `404` if the document does not belong to this project.

---

## Analytics

### `GET /analytics` — Dashboard page  (`analytics.index`)

Inertia page backed by [ComputeUsageMetrics](../app/Actions/Analytics/ComputeUsageMetrics.php). Results are cached for 5 minutes under `gail:usage-metrics:{days}`.

Shared props on the page:

| Prop | Type | Shape |
|---|---|---|
| `range_days` | int | Defaults to 30 |
| `totals` | object | `{messages, user_messages, assistant_messages, total_tokens, prompt_tokens, completion_tokens, tool_calls}` |
| `messages_per_day` | array | `[{date, count}, ...]` with 30 entries |
| `tokens_per_day` | array | `[{date, prompt, completion}, ...]` with 30 entries |
| `tool_usage` | array | `[{name, count}, ...]` sorted descending |
| `model_breakdown` | array | `[{model, provider, messages, tokens}, ...]` sorted by messages |

Token aggregation reads `usage.prompt_tokens` and `usage.completion_tokens` from the JSON `usage` column via SQLite `json_extract`. Tolerant of malformed JSON (returns 0 for unparseable rows).

---

## Inertia shared props

Every Inertia page receives these, defined in [HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php):

| Prop | Type |
|---|---|
| `name` | string — `config('app.name')` |
| `auth.user` | object or null |
| `toolLabels` | `Record<string, string>` — class short name → human label, built from every `DisplayableTool` tagged `ai.tools.chat` or `ai.tools.mysql_database` |
