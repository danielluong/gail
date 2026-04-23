# Contributing to GAIL

Short-form guide for landing changes safely. For the full picture read
[CLAUDE.md](CLAUDE.md) (project rules) and [docs/architecture.md](docs/architecture.md)
(system design).

## Local setup

```bash
composer install
npm install              # also activates husky pre-commit hooks
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Services expected: PostgreSQL 14+ with pgvector, Ollama running locally.
See [docs/development.md](docs/development.md) for the full bootstrap.

## Running the stack

```bash
composer run dev         # artisan serve + queue + pail + vite, all at once
```

Individual processes, if you prefer:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
npm run dev
```

## Before you commit

Pre-commit hooks run automatically via husky + lint-staged:

- `*.php` → Pint
- `resources/**/*.{ts,tsx,js,jsx}` → ESLint + Prettier
- `resources/**/*.{css,json,md}` → Prettier

If you need to run the full quality gates manually:

```bash
composer lint            # Pint
composer lint:types      # PHPStan
npm run lint:check       # ESLint
npm run format:check     # Prettier
npm run types:check      # tsc --noEmit
vendor/bin/pest --compact
```

Or the all-in-one:

```bash
composer ci:check        # lint + format + types + tests
```

## Adding a new agent

Single-agent workflow: see [docs/adding-a-single-agent.md](docs/adding-a-single-agent.md).
Multi-agent workflow: see [docs/adding-a-multi-agent-workflow.md](docs/adding-a-multi-agent-workflow.md).

Every new plugin ships with at least one integration test through the
`AgentKernel`. The kernel is the only orchestrator — never call
`$pipeline->run()` or `$step->run()` directly outside of
`app/Ai/Workflow/Kernel/`.

## Architectural guardrails

- Controllers are thin. Validation lives in Form Requests; orchestration
  lives in Actions (`app/Actions/`); LLM work lives in Agents
  (`app/Ai/Agents/`).
- All agent execution flows through `AgentKernel::run()` or
  `AgentKernel::stream()`. No exceptions.
- Plugin boundaries are enforced by arch tests in
  [tests/Feature/ArchitectureTest.php](tests/Feature/ArchitectureTest.php).
- Don't widen `array<string, mixed>` on kernel/contract return types.
  If a new field is needed, add it to the DTO.

## Testing

- Feature tests for HTTP flows and cross-layer behavior.
- Unit tests for pure logic (kernel, retry strategies, parsers, SQL linters).
- Use factories over hand-built models. Fake agents via their static
  `::fake([...])` method — see [tests/Feature/StreamChatTest.php](tests/Feature/StreamChatTest.php)
  for examples.

Run the suite compact:

```bash
vendor/bin/pest --compact
```

Run a single file or filter:

```bash
vendor/bin/pest tests/Feature/StreamChatTest.php
vendor/bin/pest --filter "stream yields text delta events"
```

## Commit messages

Follow the style of recent commits (`git log --oneline -10`):
imperative mood, one short line, explain the **why** in the body when
non-obvious. No conventional-commits prefixes required.

## Reporting issues

Open a GitHub issue with:

- What you expected vs. what happened
- Reproduction steps
- Relevant log excerpts from `storage/logs/` or `php artisan pail` output
- Your PHP, Node, and Laravel versions
