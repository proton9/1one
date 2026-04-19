# Fund Transfer Gateway

A secure API for transferring funds between accounts, built with PHP 8.3 / Symfony 7, MySQL 8, and Redis 7. Demonstrates production-ready patterns: double-entry ledger, pessimistic locking, idempotency, MAC-authenticated upstream calls, and HMAC-signed webhook callbacks.

## Quick Start

```bash
cp .env.example .env   # edit .env and set real secrets
docker compose up --build
```

`docker compose` will refuse to start if required secrets (`APP_SECRET`, `MYSQL_PASSWORD`, `PROVIDER_MAC_KEY`, `WEBHOOK_SECRET`, …) are missing from `.env`. `.env` is git-ignored; never commit real secrets.

This starts 6 services:
- **gateway** (`:8000`) — Symfony API
- **worker** — Messenger consumer for async jobs
- **mock-provider** (`:8001`) — simulates upstream payment processor
- **mock-merchant** (`:8002`) — simulates merchant receiving webhooks
- **mysql** — persistence
- **redis** — message queue + idempotency store

Once running, run the database migration:

```bash
docker compose exec gateway php bin/console doctrine:migrations:migrate --no-interaction
```

## API Endpoints

### Create Transfer

```bash
curl -X POST http://localhost:8000/transfers \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: unique-key-123" \
  -d '{
    "source_account_id": 1,
    "dest_account_id": 2,
    "amount": 1500,
    "callback_url": "http://mock-merchant:8002/webhook"
  }'
```

**Response** `202 Accepted`:
```json
{
  "transfer_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "reserved"
}
```

- `amount` is in **cents** (1500 = 15.00 EUR)
- `callback_url` is optional — if provided, a signed webhook fires when processing completes
- `X-Idempotency-Key` header prevents duplicate transfers (24h TTL)

### Get Transfer Status

```bash
curl http://localhost:8000/transfers/{transfer_id}
```

**Response** `200 OK`:
```json
{
  "transfer_id": "550e8400-e29b-41d4-a716-446655440000",
  "source_account_id": 1,
  "dest_account_id": 2,
  "amount": 1500,
  "currency": "EUR",
  "status": "done",
  "failure_reason": null,
  "created_at": "2026-04-16T12:00:00+00:00",
  "updated_at": "2026-04-16T12:00:01+00:00"
}
```

### Get Account Balance

```bash
curl http://localhost:8000/accounts/1/balance
```

**Response** `200 OK`:
```json
{
  "account_id": 1,
  "holder_name": "Alice",
  "balance": 8500,
  "currency": "EUR"
}
```

## Architecture

```
┌──────────────┐     POST /transfers     ┌──────────────┐
│              │ ◄────────────────────── │              │
│   Gateway    │      202 Accepted       │   Merchant   │
│  (Symfony)   │ ───────────────────── ► │              │
│              │                          └──────┬───────┘
│              │                                 │
│   ┌──────┐  │  ProcessTransferMessage          │ Webhook callback
│   │Redis │◄─┤─────────────────────────┐        │ (form-encoded + HMAC)
│   │Queue │  │                         │        │
│   └──┬───┘  │                         ▼        │
│      │      │                    ┌─────────┐   │
│      │      │  MAC auth POST     │  Worker  │──┘
│      │      │ ──────────────── ► │          │
│      │      │                    └─────────┘
│   ┌──┴───┐  │                         │
│   │MySQL │  │                         │
│   │(InnoDB)│ │  POST /process          ▼
│   └──────┘  │ ──────────────── ► ┌──────────────┐
│              │  MAC auth header   │    Mock       │
└──────────────┘ ◄──────────────── │   Provider    │
                   JSON response    └──────────────┘
```

### Transfer Status Machine

```
reserved → processing → done
                      ↘ failed
```

### Data Model

**Double-entry ledger**: every transfer creates exactly two `ledger_entries` — a debit on the source account and a credit on the destination. Account balances are updated atomically within the same transaction.

```
accounts        ← holds balance (integer cents), locked with SELECT FOR UPDATE
transfers       ← tracks status, links source ↔ dest, stores callback_url
ledger_entries  ← audit trail: debit + credit per transfer
```

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Money as integer cents** | Eliminates floating-point rounding errors |
| **Pessimistic locking (SELECT FOR UPDATE)** | Prevents race conditions on concurrent transfers touching the same account; accounts locked in ID order to avoid deadlocks |
| **Double-entry ledger** | Full audit trail; ledger entries are append-only and never modified |
| **Idempotency via Redis** | `X-Idempotency-Key` checked before processing, stored with 24h TTL; safe retries for network failures |
| **MAC authentication** | Mirrors real auth pattern — HMAC-SHA256 over normalized request string |
| **Form-encoded webhooks** | Matches callback format; signed with `X-Webhook-Signature: sha256=<base64>` |
| **Messenger + Redis transport** | Async processing with built-in retry and exponential backoff |
| **UUID transfer IDs** | Non-guessable, no information leakage about transfer volume |

## Non-Goals (documented, not built)

These are intentionally omitted for scope but would be necessary in production:

- **JWT/OAuth merchant authentication** — currently no auth on the API itself; in production, each merchant would have API keys and the gateway would verify identity
- **KYC / compliance layer** — no identity verification or sanctions screening
- **Multi-currency support** — all transfers assume EUR; real system needs FX rates and currency conversion
- **SEPA-specific fields** — no IBAN/BIC handling, though the account model could be extended with IBAN columns
- **Rate limiting** — would use Redis sliding window counters per merchant
- **Pagination** — transfer listing endpoint with cursor-based pagination
- **Admin dashboard** — internal tooling for operations

## Running Tests

```bash
# Create test database first
docker compose exec gateway php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec gateway php bin/console doctrine:migrations:migrate --env=test --no-interaction

# Run tests
docker compose exec gateway php bin/phpunit
```

## Project Structure

```
gateway/               ← Symfony application
├── src/
│   ├── Controller/    ← HTTP layer (TransferController)
│   ├── Domain/        ← Entities (Account, Transfer, LedgerEntry)
│   ├── Service/       ← Business logic (TransferService, IdempotencyService)
│   ├── Messenger/     ← Async messages + handlers
│   └── Infrastructure/← External integrations (provider client, webhooks)
├── migrations/        ← Doctrine migrations
├── tests/             ← Integration tests
└── config/            ← Symfony configuration

mock-provider/         ← Simulates upstream payment processor
mock-merchant/         ← Simulates merchant webhook receiver
```

## Time Spent

~3 hours

## AI Tools Used

- **Claude Code** (Anthropic) — architecture design, code generation, documentation
- Prompts used: project scaffolding, API pattern research, implementation planning, code review
