# Test Inventory — Full Coverage Plan

Target: >95% line/branch coverage across all gateway source files.

Legend:
- **[U]** = Unit test (no DB, no Redis, mocked dependencies)
- **[I]** = Integration test (real HTTP kernel, real DB)

---

## 1. Domain Layer — Unit Tests

### `Account.php` → `tests/Unit/Domain/Account/AccountTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 1 | Constructor sets holderName, balance, currency, createdAt | `__construct` |
| 2 | Constructor uses defaults (0 balance, EUR currency) | `__construct` default args |
| 3 | `debit()` reduces balance correctly | `debit` happy path |
| 4 | `debit()` with exact balance (zero remaining) succeeds | `debit` edge case |
| 5 | `debit()` throws `DomainException` on insufficient funds | `debit` insufficient branch |
| 6 | `debit()` throws `InvalidArgumentException` on zero amount | `debit` zero guard |
| 7 | `debit()` throws `InvalidArgumentException` on negative amount | `debit` negative guard |
| 8 | `credit()` increases balance correctly | `credit` happy path |
| 9 | `credit()` throws `InvalidArgumentException` on zero amount | `credit` zero guard |
| 10 | `credit()` throws `InvalidArgumentException` on negative amount | `credit` negative guard |
| 11 | `getId()` returns null before persistence | `getId` |
| 12 | Getters return constructed values | all getters |

### `Transfer.php` → `tests/Unit/Domain/Transfer/TransferTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 13 | Constructor generates UUID v4 id | `__construct` |
| 14 | Constructor sets all fields correctly | `__construct` |
| 15 | Initial status is `reserved` | `__construct` |
| 16 | Currency is inherited from source account | `__construct` |
| 17 | `callbackUrl` and `idempotencyKey` are nullable | `__construct` optional args |
| 18 | `markProcessing()` sets status to processing, updates updatedAt | `markProcessing` |
| 19 | `markDone()` sets status to done, updates updatedAt | `markDone` |
| 20 | `markFailed()` sets status to failed + failure reason, updates updatedAt | `markFailed` |
| 21 | All getters return expected values | all getters |

### `TransferStatus.php` → `tests/Unit/Domain/Transfer/TransferStatusTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 22 | Enum has exactly 4 cases | enum completeness |
| 23 | Backed values match expected strings | `->value` |
| 24 | `from()` works for all valid strings | `from()` |
| 25 | `from()` throws on invalid string | `from()` error |

### `LedgerEntry.php` → `tests/Unit/Domain/Ledger/LedgerEntryTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 26 | Constructor sets transfer, account, direction, amount, createdAt | `__construct` |
| 27 | `getId()` returns null before persistence | `getId` |
| 28 | All getters return constructed values | all getters |

---

## 2. Service Layer — Unit Tests

### `TransferService.php` → `tests/Unit/Service/TransferServiceTest.php`

Dependencies to mock: `EntityManagerInterface`, `MessageBusInterface`, `IdempotencyService`, `LoggerInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 29 | `createTransfer()` — happy path: debits source, credits dest, persists transfer + 2 ledger entries, dispatches message | `createTransfer` happy |
| 30 | `createTransfer()` — same account throws `InvalidArgumentException` | same-account guard |
| 31 | `createTransfer()` — zero amount throws `InvalidArgumentException` | zero-amount guard |
| 32 | `createTransfer()` — negative amount throws `InvalidArgumentException` | negative-amount guard |
| 33 | `createTransfer()` — idempotency key hit returns existing transfer | idempotency branch (key exists, transfer found) |
| 34 | `createTransfer()` — idempotency key hit but transfer deleted falls through to create | idempotency branch (key exists, transfer null) |
| 35 | `createTransfer()` — null idempotency key skips check entirely | null key branch |
| 36 | `createTransfer()` — source account not found throws `InvalidArgumentException` | account-not-found branch |
| 37 | `createTransfer()` — dest account not found throws `InvalidArgumentException` | account-not-found branch |
| 38 | `createTransfer()` — insufficient funds rolls back transaction | DomainException + rollback |
| 39 | `createTransfer()` — accounts locked in ascending ID order (source > dest) | deadlock prevention |
| 40 | `createTransfer()` — idempotency key stored after successful commit | post-commit store |
| 41 | `createTransfer()` — dispatches `ProcessTransferMessage` with correct transfer ID | message dispatch |
| 42 | `createTransfer()` — on DB exception, transaction is rolled back | rollback branch |
| 43 | `getTransfer()` — returns transfer when found | happy path |
| 44 | `getTransfer()` — returns null when not found | null branch |
| 45 | `getAccount()` — returns account when found | happy path |
| 46 | `getAccount()` — returns null when not found | null branch |

### `IdempotencyService.php` → `tests/Unit/Service/IdempotencyServiceTest.php`

Note: needs a mock/stub Redis or the constructor refactored to accept a Redis instance for testability.

| # | Test | Method/Branch |
|---|------|---------------|
| 47 | `check()` — returns transfer ID when key exists | happy path |
| 48 | `check()` — returns null when key does not exist | miss branch |
| 49 | `store()` — stores key with correct TTL | happy path |
| 50 | Constructor connects to parsed Redis DSN | `__construct` |

---

## 3. Controller Layer — Unit Tests

### `TransferController.php` → `tests/Unit/Controller/TransferControllerTest.php`

Dependencies to mock: `TransferService`, `LoggerInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 51 | `create()` — valid request returns 202 with transfer_id and status | happy path |
| 52 | `create()` — invalid JSON body returns 400 | JSON parse branch |
| 53 | `create()` — missing `source_account_id` returns 400 with field list | missing fields branch |
| 54 | `create()` — missing `dest_account_id` returns 400 | missing fields branch |
| 55 | `create()` — missing `amount` returns 400 | missing fields branch |
| 56 | `create()` — `InvalidArgumentException` from service returns 400 | catch branch |
| 57 | `create()` — `DomainException` from service returns 422 | catch branch |
| 58 | `create()` — unexpected exception returns 500 and logs error | catch-all branch |
| 59 | `create()` — `X-Idempotency-Key` header is passed to service | header extraction |
| 60 | `create()` — `callback_url` is optional (null when absent) | optional field |
| 61 | `show()` — existing transfer returns 200 with all fields | happy path |
| 62 | `show()` — non-existent transfer returns 404 | null branch |
| 63 | `balance()` — existing account returns 200 with balance | happy path |
| 64 | `balance()` — non-existent account returns 404 | null branch |

---

## 4. Messenger Handlers — Unit Tests

### `ProcessTransferHandler.php` → `tests/Unit/Messenger/Handler/ProcessTransferHandlerTest.php`

Dependencies to mock: `EntityManagerInterface`, `ProviderClientInterface`, `MessageBusInterface`, `LoggerInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 65 | Transfer not found — logs error and returns early | null transfer branch |
| 66 | Provider returns `completed` — marks done, flushes, dispatches webhook | happy path |
| 67 | Provider returns non-completed — marks failed with reason, flushes, dispatches webhook | failed branch |
| 68 | Provider returns non-completed without reason — uses default failure message | missing reason branch |
| 69 | No callback URL — does not dispatch webhook message | no-callback branch (success) |
| 70 | No callback URL + failure — does not dispatch webhook message | no-callback branch (failure) |
| 71 | Provider throws exception — marks failed, flushes, dispatches webhook, re-throws | exception branch |
| 72 | Provider throws exception + no callback URL — marks failed, re-throws, no webhook | exception + no-callback |
| 73 | Transfer status is set to `processing` before provider call | pre-call status |

### `DispatchWebhookHandler.php` → `tests/Unit/Messenger/Handler/DispatchWebhookHandlerTest.php`

Dependencies to mock: `EntityManagerInterface`, `WebhookDispatcher`, `LoggerInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 74 | Transfer not found — logs error and returns early | null transfer branch |
| 75 | Transfer has no callback URL — returns early | null callback branch |
| 76 | Webhook dispatched successfully — logs info | happy path |
| 77 | Webhook dispatcher throws — logs warning and re-throws | exception branch |

---

## 5. Infrastructure Layer — Unit Tests

### `MockProviderClient.php` → `tests/Unit/Infrastructure/Provider/MockProviderClientTest.php`

Dependencies to mock: `HttpClientInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 78 | `processTransfer()` — sends POST to correct URL with JSON body | request construction |
| 79 | `processTransfer()` — MAC Authorization header has correct format | header format |
| 80 | `processTransfer()` — MAC signature is valid HMAC-SHA256 | signature correctness |
| 81 | `processTransfer()` — 200 response returns decoded array | happy path |
| 82 | `processTransfer()` — 4xx/5xx response returns `{status: failed, reason: ...}` | error branch |
| 83 | `buildMacHeader()` — normalized string includes all fields in order | private method via behavior |
| 84 | `buildMacHeader()` — parses host and port from baseUrl | URL parsing |

### `WebhookDispatcher.php` → `tests/Unit/Infrastructure/Webhook/WebhookDispatcherTest.php`

Dependencies to mock: `HttpClientInterface`

| # | Test | Method/Branch |
|---|------|---------------|
| 85 | `dispatch()` — sends POST with form-encoded body | request construction |
| 86 | `dispatch()` — Content-Type is `application/x-www-form-urlencoded` | header |
| 87 | `dispatch()` — X-Webhook-Signature is valid HMAC-SHA256 of body | signature correctness |
| 88 | `dispatch()` — payload contains transfer_id, status, amount, currency, date | payload fields |
| 89 | `dispatch()` — 200 response does not throw | happy path |
| 90 | `dispatch()` — 4xx response throws RuntimeException | error branch |
| 91 | `dispatch()` — 5xx response throws RuntimeException | error branch |

---

## 6. Message DTOs — Unit Tests

### `ProcessTransferMessage.php` → `tests/Unit/Messenger/Message/ProcessTransferMessageTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 92 | Constructor stores transfer ID | `__construct` |
| 93 | `getTransferId()` returns correct value | getter |

### `DispatchWebhookMessage.php` → `tests/Unit/Messenger/Message/DispatchWebhookMessageTest.php`

| # | Test | Method/Branch |
|---|------|---------------|
| 94 | Constructor stores transfer ID | `__construct` |
| 95 | `getTransferId()` returns correct value | getter |

---

## 7. Integration Tests (HTTP Kernel + DB)

### `tests/Integration/TransferApiTest.php`

| # | Test | Scenario |
|---|------|----------|
| 96 | POST /transfers — valid request returns 202 with transfer_id + status=reserved | happy path |
| 97 | POST /transfers — response JSON has correct structure | schema validation |
| 98 | GET /transfers/{id} — returns full transfer details after creation | read-after-write |
| 99 | GET /transfers/{id} — non-existent UUID returns 404 | not found |
| 100 | GET /transfers/{id} — malformed ID returns 404 | bad input |
| 101 | POST /transfers — insufficient balance returns 422 | business rule |
| 102 | POST /transfers — same source and dest returns 400 | validation |
| 103 | POST /transfers — missing required fields returns 400 with field list | validation |
| 104 | POST /transfers — invalid JSON body returns 400 | parsing |
| 105 | POST /transfers — empty body returns 400 | edge case |
| 106 | POST /transfers — negative amount returns 400 | validation |
| 107 | POST /transfers — zero amount returns 400 | validation |
| 108 | POST /transfers — idempotency key returns same transfer_id on retry | idempotency |
| 109 | POST /transfers — different payload with same idempotency key returns original | idempotency semantics |
| 110 | GET /accounts/{id}/balance — existing account returns balance | happy path |
| 111 | GET /accounts/{id}/balance — non-existent account returns 404 | not found |
| 112 | POST /transfers — balance decreases on source, increases on dest | ledger correctness |
| 113 | POST /transfers — creates exactly 2 ledger entries (debit + credit) | audit trail |
| 114 | POST /transfers — concurrent requests don't create negative balance | concurrency (if feasible) |

---

## Summary

| Category | Test File | Test Count |
|----------|-----------|------------|
| Account entity | Unit/Domain/Account/AccountTest | 12 |
| Transfer entity | Unit/Domain/Transfer/TransferTest | 9 |
| TransferStatus enum | Unit/Domain/Transfer/TransferStatusTest | 4 |
| LedgerEntry entity | Unit/Domain/Ledger/LedgerEntryTest | 3 |
| TransferService | Unit/Service/TransferServiceTest | 18 |
| IdempotencyService | Unit/Service/IdempotencyServiceTest | 4 |
| TransferController | Unit/Controller/TransferControllerTest | 14 |
| ProcessTransferHandler | Unit/Messenger/Handler/ProcessTransferHandlerTest | 9 |
| DispatchWebhookHandler | Unit/Messenger/Handler/DispatchWebhookHandlerTest | 4 |
| MockProviderClient | Unit/Infrastructure/Provider/MockProviderClientTest | 7 |
| WebhookDispatcher | Unit/Infrastructure/Webhook/WebhookDispatcherTest | 7 |
| ProcessTransferMessage | Unit/Messenger/Message/ProcessTransferMessageTest | 2 |
| DispatchWebhookMessage | Unit/Messenger/Message/DispatchWebhookMessageTest | 2 |
| Integration (API) | Integration/TransferApiTest | 19 |
| **Total** | **14 test files** | **114 tests** |

This covers every public method, every conditional branch, every exception path, and every HTTP status code in the application.


#
Tests are not isolated across runs (they mutate shared accounts), so for repeated runs use the reset-and-rerun sequence:            
docker compose exec gateway php bin/console doctrine:database:drop --env=test --force                                             
docker compose exec gateway php bin/console doctrine:database:create --env=test                                                     
docker compose exec gateway php bin/console doctrine:migrations:migrate --env=test --no-interaction                                 
docker compose exec gateway php bin/console doctrine:fixtures:load --env=test --no-interaction                                      
docker compose exec -e APP_ENV=test gateway vendor/bin/phpunit                                                                      
                                                                    