# Development Workflow Rules

These are mandatory instructions for all AI-assisted development on this project. They override default behavior.

## 1. Test-Alongside-Code Rule

Every new file, feature, function, or modification **MUST** have corresponding tests written alongside it — not after the fact, not in a separate pass.

- **Unit tests** for every public method in every class
- **Integration tests** for every API endpoint and async handler
- Target: **>95% line/branch coverage**
- Tests are not optional. Untested code is unfinished code.

## 2. Syntax Check Rule

After writing or modifying **any** PHP file, immediately run:
```bash
php -l <file>
```
Do not proceed to the next file until the syntax check passes.

## 3. Test Gate Rule

Before any of the following, **all tests must pass**:
- Generating or updating Dockerfiles
- Committing code
- Declaring a task complete

Run the full test suite:
```bash
# In Docker (recommended — has the redis PHP extension installed):
docker compose exec -e APP_ENV=test gateway vendor/bin/phpunit

# On host: requires the `redis` PHP extension (pecl install redis).
# Without it, every controller will 500 with "Class \"Redis\" not found".
cd gateway && APP_ENV=test vendor/bin/phpunit
```

CI also gates on **OpenAPI spec quality** — the `spec-quality` job runs after `ci`:
- **Spectral** lints `/api/doc.json` against `spectral:oas` (config in `.spectral.yaml`).
- **Schemathesis** runs property-based contract tests against the booted gateway.

Both must pass before merge. If you change an endpoint or DTO, regenerate locally and skim the diff: `curl -s http://localhost:8000/api/doc.json | jq .`.

## 4. No Deprecated APIs

Never use deprecated methods, classes, or patterns. Before using any API:
- Check for `@deprecated` annotations in the source
- If a method shows a deprecation warning in tests, fix it immediately
- Use the replacement API documented in the deprecation notice
- This applies to Doctrine DBAL/ORM, Symfony, PHPUnit, and all dependencies

Examples:
- Doctrine DBAL: `setPrimaryKey()` → `addPrimaryKeyConstraint()` with `PrimaryKeyConstraint::editor()`
- PHPUnit 12: `isType('string')` → `isString()`
- PHPUnit 12: `createMock()` without `expects()` → use `createStub()` instead

## 5. Incremental Verification

Do not batch work. For each logical unit of change:
1. Write/modify the source code
2. Run `php -l` on every changed file
3. Write/update corresponding tests
4. Run `php -l` on every test file
5. Run the relevant test suite
6. Only then move to the next unit

## 6. Documentation Sync

When code changes, update the corresponding documentation:
- `CLAUDE.md` (this file) — workflow rules, CI gates (instructions for the AI)
- `docs/architecture.md` — system design, features, data model, API specs (documentation for developers)
- `docs/test-inventory.md` — test coverage map (kept in sync with actual tests)

## Stack
PHP 8.3, Symfony 7, MySQL 8, Redis 7, Docker Compose

## Key Files
- `gateway/src/Service/TransferService.php` — core business logic, pessimistic locking
- `gateway/src/Controller/TransferController.php` — HTTP endpoints
- `gateway/src/Messenger/Handler/` — async processing + webhook delivery
- `gateway/src/Infrastructure/Provider/MockProviderClient.php` — MAC authenticated upstream calls
- `gateway/src/Infrastructure/Webhook/WebhookDispatcher.php` — HMAC signed callbacks
- `docs/test-inventory.md` — comprehensive test plan (114 tests across 14 files)

## Running
```bash
docker compose up --build
# Migrations run automatically on gateway startup
```

## Testing
```bash
docker compose exec gateway php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec gateway php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec gateway php bin/phpunit
```
