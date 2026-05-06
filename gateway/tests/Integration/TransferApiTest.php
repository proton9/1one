<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for the Transfer API.
 *
 * These tests require a running MySQL database (test environment).
 * Run via: docker compose exec gateway php bin/phpunit --testsuite Integration
 *
 * Pre-requisites:
 *   docker compose exec gateway php bin/console doctrine:database:create --env=test --if-not-exists
 *   docker compose exec gateway php bin/console doctrine:migrations:migrate --env=test --no-interaction
 */
class TransferApiTest extends WebTestCase
{
    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = null;
    }

    private function client(): KernelBrowser
    {
        return $this->client ??= static::createClient();
    }

    private function validPayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'source_account_id' => 1,
            'dest_account_id' => 2,
            'amount' => 500,
            'callback_url' => 'http://mock-merchant:8002/webhook',
        ], $overrides));
    }

    private function createTransfer(array $overrides = [], ?string $idempotencyKey = null): array
    {
        $client = $this->client();
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['HTTP_X_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $client->request('POST', '/transfers', [], [], $headers, $this->validPayload($overrides));

        return [
            'client' => $client,
            'response' => $client->getResponse(),
            'data' => json_decode($client->getResponse()->getContent(), true),
        ];
    }

    // --- POST /transfers ---

    public function testCreateTransferReturns202WithTransferIdAndStatus(): void
    {
        $result = $this->createTransfer([], 'test-create-' . uniqid());

        $this->assertSame(Response::HTTP_ACCEPTED, $result['response']->getStatusCode());
        $this->assertArrayHasKey('transfer_id', $result['data']);
        $this->assertSame('reserved', $result['data']['status']);
    }

    public function testCreateTransferResponseHasCorrectJsonStructure(): void
    {
        $result = $this->createTransfer([], 'test-structure-' . uniqid());

        $this->assertSame(Response::HTTP_ACCEPTED, $result['response']->getStatusCode());
        $data = $result['data'];
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('transfer_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $data['transfer_id']);
    }

    // --- GET /transfers/{id} ---

    public function testGetTransferReturnsDetailsAfterCreation(): void
    {
        $create = $this->createTransfer(['amount' => 300], 'test-get-' . uniqid());
        $transferId = $create['data']['transfer_id'];

        $client = $this->client();
        $client->request('GET', '/transfers/' . $transferId);

        $response = $client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame($transferId, $data['transfer_id']);
        $this->assertSame(1, $data['source_account_id']);
        $this->assertSame(2, $data['dest_account_id']);
        $this->assertSame(300, $data['amount']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testGetNonExistentTransferReturns404(): void
    {
        $client = $this->client();
        $client->request('GET', '/transfers/00000000-0000-0000-0000-000000000000');

        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    public function testGetTransferWithMalformedIdReturns404(): void
    {
        $client = $this->client();
        $client->request('GET', '/transfers/not-a-uuid');

        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    // --- Validation ---

    public function testInsufficientBalanceReturns422(): void
    {
        $result = $this->createTransfer(['amount' => 99999999], 'test-insufficient-' . uniqid());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $result['response']->getStatusCode());
    }

    public function testSameSourceAndDestReturns400(): void
    {
        $result = $this->createTransfer(
            ['source_account_id' => 1, 'dest_account_id' => 1],
            'test-same-' . uniqid(),
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $result['response']->getStatusCode());
    }

    public function testMissingRequiredFieldsReturns422(): void
    {
        $client = $this->client();
        $client->request('POST', '/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['source_account_id' => 1]));

        // MapRequestPayload validation failure → 422.
        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $client->getResponse()->getStatusCode(),
        );
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $client = $this->client();
        $client->request('POST', '/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'this is not json');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testEmptyBodyReturns400(): void
    {
        $client = $this->client();
        $client->request('POST', '/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        // Empty body fails JSON deserialization in MapRequestPayload before
        // validation runs, so Symfony returns 400 (same as malformed JSON).
        $this->assertSame(
            Response::HTTP_BAD_REQUEST,
            $client->getResponse()->getStatusCode(),
        );
    }

    public function testNegativeAmountReturns422(): void
    {
        $result = $this->createTransfer(['amount' => -100]);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $result['response']->getStatusCode(),
        );
    }

    public function testZeroAmountReturns422(): void
    {
        $result = $this->createTransfer(['amount' => 0]);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $result['response']->getStatusCode(),
        );
    }

    // --- Idempotency ---

    public function testIdempotencyKeyReturnsSameTransferOnRetry(): void
    {
        $key = 'idempotent-' . uniqid();

        $first = $this->createTransfer(['amount' => 100], $key);
        $this->assertSame(Response::HTTP_ACCEPTED, $first['response']->getStatusCode());

        $second = $this->createTransfer(['amount' => 100], $key);
        $this->assertSame(Response::HTTP_ACCEPTED, $second['response']->getStatusCode());

        $this->assertSame($first['data']['transfer_id'], $second['data']['transfer_id']);
    }

    public function testSameIdempotencyKeyDifferentAmountReturns409PayloadMismatch(): void
    {
        $key = 'idempotent-diff-amount-' . uniqid();

        $first = $this->createTransfer(['amount' => 100], $key);
        $this->assertSame(Response::HTTP_ACCEPTED, $first['response']->getStatusCode());

        $second = $this->createTransfer(['amount' => 999], $key);

        $this->assertSame(Response::HTTP_CONFLICT, $second['response']->getStatusCode());
        $this->assertSame('idempotency_payload_mismatch', $second['data']['code']);
    }

    public function testSameIdempotencyKeyDifferentDestAccountReturns409PayloadMismatch(): void
    {
        $key = 'idempotent-diff-dest-' . uniqid();

        $first = $this->createTransfer(['dest_account_id' => 2], $key);
        $this->assertSame(Response::HTTP_ACCEPTED, $first['response']->getStatusCode());

        $second = $this->createTransfer(['dest_account_id' => 3], $key);

        $this->assertSame(Response::HTTP_CONFLICT, $second['response']->getStatusCode());
        $this->assertSame('idempotency_payload_mismatch', $second['data']['code']);
    }

    // --- GET /accounts/{id}/balance ---

    public function testGetAccountBalanceReturns200(): void
    {
        $client = $this->client();
        $client->request('GET', '/accounts/1/balance');

        $response = $client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(1, $data['account_id']);
        $this->assertArrayHasKey('balance', $data);
        $this->assertSame('EUR', $data['currency']);
        $this->assertArrayHasKey('holder_name', $data);
    }

    public function testGetNonExistentAccountReturns404(): void
    {
        $client = $this->client();
        $client->request('GET', '/accounts/9999/balance');

        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    // --- Ledger correctness ---

    public function testTransferDecreasesSourceAndIncreasesDestBalance(): void
    {
        $client = $this->client();

        // Get initial balances
        $client->request('GET', '/accounts/1/balance');
        $sourceBefore = json_decode($client->getResponse()->getContent(), true)['balance'];

        $client->request('GET', '/accounts/2/balance');
        $destBefore = json_decode($client->getResponse()->getContent(), true)['balance'];

        // Create transfer
        $this->createTransfer(['amount' => 200], 'test-ledger-' . uniqid());

        // Check updated balances
        $client->request('GET', '/accounts/1/balance');
        $sourceAfter = json_decode($client->getResponse()->getContent(), true)['balance'];

        $client->request('GET', '/accounts/2/balance');
        $destAfter = json_decode($client->getResponse()->getContent(), true)['balance'];

        $this->assertSame($sourceBefore - 200, $sourceAfter);
        $this->assertSame($destBefore + 200, $destAfter);
    }
}
