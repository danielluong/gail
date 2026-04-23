# Development guide

Everything you need to make changes to Gail safely.

---

## 1. Daily workflow

```bash
# Start everything (Laravel server + queue + logs + Vite HMR)
composer run dev

# OR, if you use Herd for the app server:
npm run dev                  # Vite only
php artisan queue:listen     # background jobs
php artisan pail             # live logs
```

Herd serves the app at `https://gail.test` automatically. The `composer run dev` script runs `php artisan serve` on port 8000.

---

## 2. Quality gates (run before every push)

```bash
composer lint               # Pint --parallel (autofix)
composer lint:check         # Pint --test (CI mode)
composer lint:types         # Larastan level 5 (phpstan.neon + baseline)
php artisan test --compact
npm run lint
npm run format
npm run types:check
npm run build
```

CI (`.github/workflows/`) runs Pint, Larastan, ESLint, Prettier, and the full Pest suite on every push.

### Pre-commit hooks

A husky pre-commit hook runs `lint-staged` on staged files only:

- `*.php` → `vendor/bin/pint --format agent`
- `resources/**/*.{ts,tsx,js,jsx}` → `eslint --fix` + `prettier --write`
- `resources/**/*.{css,json,md}` → `prettier --write`

The hook is activated on fresh clones by `npm install` (via the `prepare` script). PHPStan and the full test suite are **not** gated at commit time — they run in CI. Run them locally with `composer lint:types` and `php artisan test --compact` before opening a PR.

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the short-form contributor guide.

### Larastan baseline

`phpstan-baseline.neon` locks pre-existing errors so new code is held to level 5. Do **not** add new entries to the baseline; fix the underlying issue instead. If you eliminate an error, remove the corresponding entry or regenerate the baseline:

```bash
vendor/bin/phpstan analyse --generate-baseline
```

---

## 3. Adding a new tool

Tools are the primary extensibility point. The pattern:

1. **Create the class** at `app/Ai/Tools/Chat/MyTool.php`:

```php
namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MyTool implements DisplayableTool, Tool
{
    public function label(): string
    {
        return 'Did the thing';   // shown in the chat UI
    }

    public function description(): Stringable|string
    {
        return 'One or two sentences telling the LLM exactly when to use this tool and what it returns.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = trim((string) ($request['input'] ?? ''));

        if ($input === '') {
            return 'Error: No input provided.';
        }

        return "Processed: {$input}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'input' => $schema->string()
                ->description('What the tool should process.')
                ->required(),
        ];
    }
}
```

2. **Register** in [app/Providers/AiServiceProvider.php](../app/Providers/AiServiceProvider.php) inside the `ai.tools.chat` tag.
3. **Document routing** by adding a one-liner to the `# Tool routing` section in [ChatAgent::basePrompt()](../app/Ai/Agents/ChatAgent.php) so the small default model knows when to pick it.
4. **Run tests** — `tests/Feature/Ai/ToolRegistryTest.php` automatically validates every tagged tool:

```bash
php artisan test --compact --filter=ToolRegistryTest
```

The registry test enforces that every tool has a non-empty description (≥20 chars), a valid JSON schema, implements `DisplayableTool`, and returns a non-empty label.

### If your tool makes HTTP requests

Inject a `HostGuard` in the constructor and call `HostGuard::forTool('my_tool')` as the default:

```php
public function __construct(
    private readonly ?HostGuard $hostGuard = null,
) {}

private function guard(): HostGuard
{
    return $this->hostGuard ?? HostGuard::forTool('my_tool');
}
```

Then inside `handle()`:

```php
if ($this->guard()->deniedHostFor($url) !== null) {
    return 'Error: Host is not allowed.';
}
```

The shared `gail.tools.denied_hosts` baseline is automatically applied. Add per-tool extras if needed:

```php
// config/gail.php
'my_tool' => [
    'extra_denied_hosts' => ['*.suspicious.internal'],
],
```

---

## 4. Adding a context provider

Context providers inject sections into the agent's system prompt. Implement `App\Ai\Context\ContextProvider`:

```php
namespace App\Ai\Context;

use App\Models\Project;

class MyContext implements ContextProvider
{
    public function render(?Project $project): ?string
    {
        // return null to inject nothing, or a string section to append
        return "# My extra context\n\n…";
    }
}
```

Register in `AiServiceProvider::register()` inside the `ai.context_providers` tag. Providers are consulted in declaration order.

---

## 5. Adding a new message column

Every JSON column on `ConversationMessage` needs:

1. A migration
2. An entry in `protected function casts(): array` on [ConversationMessage](../app/Models/ConversationMessage.php)
3. (If displayed) an entry in `toChatUiArray()`
4. Update [ConversationMessageFactory](../database/factories/ConversationMessageFactory.php) defaults

**Warning:** `BranchConversation` copies messages via `setRawAttributes` specifically to avoid double-encoding JSON columns. If you change how columns are persisted, check that file and its test.

---

## 6. Frontend development

Inertia v3 + React 19 + Tailwind v4. Pages live at [resources/js/pages/](../resources/js/pages/). Shared props are typed in [resources/js/types/global.d.ts](../resources/js/types/global.d.ts).

The chat state is a pure reducer — every SSE event flows through `applyChatStreamEvent` in [resources/js/lib/chat-state.ts](../resources/js/lib/chat-state.ts). Unknown event types are dropped at the source whitelist in [use-chat.ts](../resources/js/hooks/use-chat.ts) so provider-specific events don't wipe state.

Wayfinder auto-generates route helpers. After adding a route, run:

```bash
npm run build   # regenerates resources/js/routes/
```

---

## 7. Debugging

```bash
# Live application logs
php artisan pail

# Tinker into the running state
php artisan tinker
# >>> app(App\Ai\Agents\ChatAgent::class)->instructions()
# >>> App\Models\Conversation::latest()->first()->messages

# SQL query log
php artisan db:monitor

# Inspect tool registry
php artisan tinker --execute 'dd(array_map("get_class", iterator_to_array(app()->tagged("ai.tools.chat"))));'

# Inspect resolved config
php artisan config:show gail
```

### Common debugging scenarios

**"The LLM isn't picking my new tool."**
Check that [ChatAgent::basePrompt()](../app/Ai/Agents/ChatAgent.php) mentions the tool by name under `# Tool routing`. Small models route almost entirely from that list.

**"Tool call history is missing after a refresh."**
Verify `ConversationMessage::toChatUiArray()` includes the data and that the `tool_calls` cast is `array`. The shape must match `applyChatStreamEvent`'s input.

**"Stream just disconnects."**
Run `php artisan pail --filter=ai` in one pane and repeat the action. `StreamChatResponse::frames` logs all exceptions to the `ai` channel.

**"Host is being blocked incorrectly."**
```bash
php artisan tinker --execute 'dd(App\Ai\Tools\Guards\HostGuard::forTool("web_fetch")->deniedHostFor("http://YOUR_URL/"));'
```
Returns the pattern that matched, or `null`.

---

## 8. Testing

See the [Testing section in README](../README.md#testing) for the basics. Gail conventions:

- **Feature tests** under `tests/Feature/` use `RefreshDatabase` via `tests/Pest.php`
- **Tool tests** mock outbound HTTP with `Http::fake()` — never hit real APIs in tests
- **Action tests** call `execute()` directly; no HTTP kernel
- **Factories** pass actual arrays to JSON columns; **never** `json_encode` in a factory (the cast will re-encode)
- **StreamChatTest** uses `ChatAgent::fake()` to stub LLM responses

When adding HTTP fakes, prefer specific URL matchers over `Http::fake()` without args, so tests fail loudly when they accidentally target unexpected endpoints.

---

## 9. Commit conventions

- One concern per commit — keep PRs small and focused.
- Commit bodies explain **why**, not what. Reserve "what" for the subject line and diff.
- Commits are SSH-signed via 1Password; CI does not require signatures but local config does.
- Co-author the LLM when it made substantial contributions:

```
Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
```
