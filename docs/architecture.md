# Architecture — Fund Transfer Gateway

## What This Is

A secure fund transfer API built for the backend engineering assignment. It demonstrates production-ready patterns for handling money movement: double-entry accounting, pessimistic locking, idempotent operations, authenticated upstream provider calls, and signed webhook callbacks to merchants.

This is **not** a full payment system. It is one clean, reliable component — a fund transfer service — built to production standards.

---

## System Overview

Three services run in Docker Compose:

```
┌──────────────────────────────────────────────────────────────────┐
│  docker-compose                                                   │
│                                                                   │
│  ┌─────────────┐   ┌─────────┐   ┌──────────────┐               │
│  │   gateway    │   │  worker │   │ mock-provider │               │
│  │  (Symfony)   │   │ (Messenger│  │ (PHP built-in)│              │
│  │  :8000       │   │ consumer)│   │  :8001        │              │
│  └──────┬───────┘   └────┬────┘   └──────────────┘               │
│         │                │                                        │
│  ┌──────┴───────┐   ┌────┴────┐   ┌──────────────┐               │
│  │    MySQL 8   │   │ Redis 7 │   │mock-merchant  │               │
│  │  (accounts,  │   │ (queue, │   │ (webhook      │               │
│  │  transfers,  │   │  idempot│   │  receiver)    │               │
│  │  ledger)     │   │  ency)  │   │  :8002        │               │
│  └──────────────┘   └─────────┘   └──────────────┘               │
└──────────────────────────────────────────────────────────────────┘
```

| Service | Role | Technology |
|---------|------|------------|
| `gateway` | Main Symfony API — receives transfer requests, validates, persists, returns responses | PHP 8.3, Symfony 7 |
| `worker` | Same codebase as gateway, runs `messenger:consume` — processes transfers via provider, fires webhooks | Symfony Messenger |
| `mock-provider` | Simulates upstream payment processor — verifies MAC auth, returns success/failure | PHP built-in server |
| `mock-merchant` | Simulates merchant — receives webhook callbacks, verifies HMAC signature, logs | PHP built-in server |
| `mysql` | Persistence — accounts, transfers, ledger entries | MySQL 8.0 |
| `redis` | Message queue (Messenger transport) + idempotency key store (24h TTL) | Redis 7 |

---

## Transfer Flow

```
1. Merchant sends:
   POST /transfers { source_account_id, dest_account_id, amount, callback_url }
   Header: X-Idempotency-Key: <unique-key>

2. Gateway:
   a. Check idempotency key in Redis → if hit, return existing transfer
   b. BEGIN TRANSACTION
   c. SELECT ... FOR UPDATE on both accounts (locked in ID order)
   d. Debit source account, credit destination account
   e. Create Transfer record (status: reserved)
   f. Create 2 LedgerEntry records (debit + credit)
   g. COMMIT
   h. Store idempotency key in Redis (TTL 24h)
   i. Dispatch ProcessTransferMessage to Redis queue
   j. Return 202 { transfer_id, status: "reserved" }

3. Worker picks up ProcessTransferMessage:
   a. Set transfer status → processing
   b. Call mock-provider POST /process with MAC auth header
   c. If provider returns "completed" → status = done
      If provider returns other → status = failed
   d. Dispatch DispatchWebhookMessage to Redis queue

4. Worker picks up DispatchWebhookMessage:
   a. Build form-encoded payload: transfer_id=X&status=Y&amount=Z&currency=W&date=D
   b. Sign with HMAC-SHA256 → X-Webhook-Signature header
   c. POST to merchant's callback_url
   d. If non-2xx → Messenger retries with exponential backoff (5 retries, 3x multiplier)
```

---

## API Endpoints

### `POST /transfers`

Create a fund transfer between two accounts.

**Request:**
```
POST /transfers
Content-Type: application/json
X-Idempotency-Key: unique-key-123  (optional but recommended)

{
  "source_account_id": 1,
  "dest_account_id": 2,
  "amount": 1500,
  "callback_url": "http://merchant.example.com/webhook"  (optional)
}
```

- `amount` is in **cents** (1500 = 15.00 EUR)
- `callback_url` — if provided, gateway fires a signed webhook when processing completes

**Responses:**

| Status | Meaning |
|--------|---------|
| `202 Accepted` | Transfer created, processing async. Body: `{ transfer_id, status }` |
| `400 Bad Request` | Invalid JSON, missing fields, same account, negative/zero amount, non-existent account |
| `422 Unprocessable Entity` | Business rule violation (insufficient funds) |
| `500 Internal Server Error` | Unexpected failure |

### `GET /transfers/{id}`

Poll the status of a transfer.

**Response `200`:**
```json
{
  "transfer_id": "uuid",
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

| Status | Meaning |
|--------|---------|
| `200 OK` | Transfer found |
| `404 Not Found` | No transfer with this ID |

### `GET /accounts/{id}/balance`

Get current balance for an account.

**Response `200`:**
```json
{
  "account_id": 1,
  "holder_name": "Alice",
  "balance": 8500,
  "currency": "EUR"
}
```

| Status | Meaning |
|--------|---------|
| `200 OK` | Account found |
| `404 Not Found` | No account with this ID |

---

## API Documentation

The OpenAPI 3.x spec for the endpoints above is generated by **NelmioApiDocBundle** from `#[Route]` attributes, typed `MapRequestPayload` DTOs, Symfony Validator constraints, and `#[OA\*]` attributes on the controllers.

| URL | Purpose |
|-----|---------|
| `GET /api/doc.json` | Raw OpenAPI 3.x JSON spec |
| `GET /api/doc` | **Redoc** UI rendered from the spec |

`info.version` is pulled at runtime from `composer.json` via `App\ApiDoc\VersionDescriber` (a Nelmio `DescriberInterface`) — bumping the package version updates the spec automatically.

Redoc is bundled into the gateway image at build time (pinned `redoc@2.5.1` downloaded into `public/vendor/redoc/`) so the UI has no runtime CDN dependency.

### CI quality gates

The `spec-quality` job in `.github/workflows/ci.yml` runs after the main test job:

- **Spectral** (`@stoplight/spectral-cli`) lints the generated spec against the `spectral:oas` ruleset (config in `.spectral.yaml`).
- **Schemathesis** runs property-based contract tests against the booted gateway, fuzzing each endpoint and verifying responses match the spec.

Both must pass before merge.

---

## Data Model

### Entity Relationship

```
accounts (1) ←──── (N) transfers ────→ (1) accounts
                         │
                         │ (1:N)
                         ▼
                   ledger_entries
```

### `accounts`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT, auto-increment | PK |
| `holder_name` | VARCHAR(255) | |
| `currency` | VARCHAR(3) | Default: EUR |
| `balance` | BIGINT | **Stored in cents** — avoids floating-point errors |
| `version` | INT | Optimistic locking counter (Doctrine `@Version`) |
| `created_at` | DATETIME | Immutable |

### `transfers`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36), UUID v4 | PK — non-sequential, non-guessable |
| `source_account_id` | INT, FK → accounts | |
| `dest_account_id` | INT, FK → accounts | |
| `amount` | BIGINT | In cents |
| `currency` | VARCHAR(3) | Inherited from source account |
| `status` | VARCHAR(20) | Enum: reserved, processing, done, failed |
| `callback_url` | VARCHAR(2048), nullable | Merchant's webhook endpoint |
| `idempotency_key` | VARCHAR(255), nullable, indexed | For dedup |
| `failure_reason` | VARCHAR(255), nullable | Populated when status=failed |
| `created_at` | DATETIME | Immutable |
| `updated_at` | DATETIME | Updated on status transitions |

### `ledger_entries`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT, auto-increment | PK |
| `transfer_id` | CHAR(36), FK → transfers | |
| `account_id` | INT, FK → accounts | |
| `direction` | VARCHAR(6) | `debit` or `credit` |
| `amount` | BIGINT | In cents |
| `created_at` | DATETIME | Immutable |

Every transfer creates **exactly two** ledger entries — one debit on the source account, one credit on the destination. This is the double-entry accounting pattern. Ledger entries are append-only and never modified.

---

## Transfer Status Machine

```
reserved → processing → done
                      ↘ failed
```

| Status | Meaning |
|--------|---------|
| `reserved` | Funds locked — transfer created, account balances updated, awaiting provider processing |
| `processing` | Worker picked up the job, calling upstream provider |
| `done` | Provider confirmed success |
| `failed` | Provider rejected or communication error — `failure_reason` populated |

---

## Security & Authentication

### MAC Authentication (Gateway → Provider)

The gateway authenticates to the mock-provider using MAC headers:

```
Authorization: MAC id="gateway-app", ts="1713300000", nonce="abc123...", mac="base64sig"
```

**Signature construction:**
```
normalized_string = join("\n", [timestamp, nonce, method, uri, host, port, body, ""])
mac = Base64(HMAC-SHA256(mac_key, normalized_string))
```

The mock-provider verifies:
1. Header has correct `MAC` prefix and all required fields
2. Timestamp is within 5-minute window (replay protection)
3. HMAC signature matches

### Webhook Signing (Gateway → Merchant)

Outgoing webhooks include an HMAC signature:

```
X-Webhook-Signature: sha256=<base64(HMAC-SHA256(secret, body))>
```

The mock-merchant verifies the signature using `hash_equals()` (timing-safe comparison).

### Idempotency

The `X-Idempotency-Key` header prevents duplicate transfers from network retries:
- Key is checked in Redis before processing
- On match: return the existing transfer (no new transfer created)
- On miss: process normally, store key → transfer_id with 24h TTL after commit
- Key is stored **after** the DB transaction commits to avoid phantom references

---

## Concurrency & Reliability

### Pessimistic Locking

Account rows are locked with `SELECT ... FOR UPDATE` during transfer creation. Both accounts are locked in **ascending ID order** to prevent deadlocks when concurrent transfers touch the same pair of accounts.

### Transaction Boundaries

The entire debit-credit-persist operation runs in a single MySQL transaction. If any step fails (insufficient funds, DB error), the entire transaction rolls back. No partial state is persisted.

### Async Processing & Retries

Symfony Messenger with Redis transport handles:
- `ProcessTransferMessage` — calls upstream provider
- `DispatchWebhookMessage` — fires merchant callback

Retry strategy: exponential backoff (1s base, 3x multiplier, max 5 retries, 5-minute cap).

---

## Project Structure

```
gateway/                      ← Symfony application
├── src/
│   ├── Controller/
│   │   └── TransferController.php     ← HTTP layer: request parsing, response formatting
│   ├── Domain/
│   │   ├── Account/
│   │   │   └── Account.php            ← Entity: balance operations (debit/credit)
│   │   ├── Transfer/
│   │   │   ├── Transfer.php           ← Entity: status transitions
│   │   │   └── TransferStatus.php     ← Backed enum: reserved|processing|done|failed
│   │   └── Ledger/
│   │       └── LedgerEntry.php        ← Entity: append-only audit trail
│   ├── Service/
│   │   ├── TransferService.php        ← Business logic: locking, ledger, dispatch
│   │   └── IdempotencyService.php     ← Redis-backed dedup
│   ├── Messenger/
│   │   ├── Message/
│   │   │   ├── ProcessTransferMessage.php
│   │   │   └── DispatchWebhookMessage.php
│   │   └── Handler/
│   │       ├── ProcessTransferHandler.php  ← Calls provider, updates status
│   │       └── DispatchWebhookHandler.php  ← Fires signed webhook
│   └── Infrastructure/
│       ├── Provider/
│       │   ├── ProviderClientInterface.php
│       │   └── MockProviderClient.php      ← MAC-authenticated HTTP client
│       └── Webhook/
│           └── WebhookDispatcher.php       ← HMAC-signed form-encoded POST
├── config/                    ← Symfony configuration
├── migrations/                ← Doctrine migrations (schema + seed data)
├── tests/                     ← Unit + integration tests
└── public/index.php           ← Symfony front controller

mock-provider/index.php        ← Simulates upstream payment processor
mock-merchant/index.php        ← Simulates merchant webhook receiver
docs/
├── architecture.md            ← This file
└── test-inventory.md          ← Comprehensive test plan (114 tests)
```

---

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Money as integer cents** | Floating-point arithmetic is unsuitable for financial calculations. Storing amounts in cents (BIGINT) eliminates rounding errors entirely. |
| **Pessimistic locking (FOR UPDATE)** | Optimistic locking requires retries at the application level. Pessimistic locking is simpler, correct, and appropriate for the expected concurrency level. Accounts locked in ID order prevent deadlocks. |
| **Double-entry ledger** | Industry standard for financial systems. Every movement of money has two entries. The ledger is append-only — entries are never modified or deleted. This provides a complete audit trail. |
| **UUID v4 transfer IDs** | Non-sequential IDs prevent information leakage about transfer volume or timing. Not guessable. |
| **Idempotency via Redis** | Network failures happen. The idempotency key allows safe retries without creating duplicate transfers. Redis is used over MySQL for this because it's a temporary mapping (24h TTL) and doesn't need ACID guarantees. |
| **Async processing via Messenger** | The provider call is I/O-bound and potentially slow. Processing it synchronously would block the HTTP response. The 202 response + async worker pattern keeps the API responsive. |
| **Form-encoded webhooks** | Matches actual callback format. |
| **MAC authentication** | Mirrors real API authentication pattern (HMAC-SHA256 over normalized request string). |
| **Exponential backoff on webhooks** | Merchants may have temporary outages. Retrying with increasing delays avoids overwhelming a recovering service. |

---

## Non-Goals (Not Built)

These are intentionally out of scope but documented for completeness:

| Feature | Why omitted | What production needs |
|---------|-------------|----------------------|
| JWT/OAuth for merchants | Assignment scope | API keys per merchant, rate limiting per key |
| KYC/compliance | Assignment scope | Identity verification, sanctions screening before transfers |
| Multi-currency | Single currency simplifies the demo | FX rate service, currency conversion, per-currency balances |
| SEPA fields | Assignment scope | IBAN/BIC columns on accounts, SEPA XML generation |
| Rate limiting | Assignment scope | Redis sliding window counters per merchant/IP |
| Transfer listing + pagination | Assignment scope | Cursor-based pagination on GET /transfers |
| Admin dashboard | Assignment scope | Internal operations tooling |
