<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\TransferController;
use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Dto\CreateTransferRequest;
use App\Exception\InsufficientFundsException;
use App\Exception\SameAccountTransferException;
use App\Service\TransferService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TransferControllerTest extends TestCase
{
    private function makeTransfer(): Transfer
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($source, 1);
        $ref->setValue($dest, 2);

        return new Transfer($source, $dest, 1500, 'http://example.com/cb', 'key-1');
    }

    private function makePayload(
        int $sourceId = 1,
        int $destId = 2,
        int $amount = 1500,
        ?string $callback = null,
    ): CreateTransferRequest {
        return new CreateTransferRequest($sourceId, $destId, $amount, $callback);
    }

    public function testCreateReturns202OnValidRequest(): void
    {
        $service = $this->createStub(TransferService::class);
        $service->method('createTransfer')->willReturn($this->makeTransfer());

        $response = (new TransferController($service))->create($this->makePayload(), new Request());

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('transfer_id', $data);
        $this->assertSame('reserved', $data['status']);
    }

    public function testCreatePassesPayloadFieldsToService(): void
    {
        $service = $this->createMock(TransferService::class);
        $service->expects($this->once())
            ->method('createTransfer')
            ->with(7, 9, 2500, 'http://example.com/cb', 'idem-42')
            ->willReturn($this->makeTransfer());

        $request = new Request();
        $request->headers->set('X-Idempotency-Key', 'idem-42');

        (new TransferController($service))->create(
            $this->makePayload(7, 9, 2500, 'http://example.com/cb'),
            $request,
        );
    }

    public function testCreatePassesNullIdempotencyKeyWhenHeaderMissing(): void
    {
        $service = $this->createMock(TransferService::class);
        $service->expects($this->once())
            ->method('createTransfer')
            ->with(1, 2, 1500, null, null)
            ->willReturn($this->makeTransfer());

        (new TransferController($service))->create($this->makePayload(), new Request());
    }

    public function testServiceBusinessExceptionsBubbleUp(): void
    {
        $service = $this->createStub(TransferService::class);
        $service->method('createTransfer')
            ->willThrowException(new SameAccountTransferException());

        $this->expectException(SameAccountTransferException::class);

        (new TransferController($service))->create($this->makePayload(1, 1), new Request());
    }

    public function testInsufficientFundsBubblesUp(): void
    {
        $service = $this->createStub(TransferService::class);
        $service->method('createTransfer')
            ->willThrowException(new InsufficientFundsException());

        $this->expectException(InsufficientFundsException::class);

        (new TransferController($service))->create($this->makePayload(1, 2, 999999), new Request());
    }

    // --- GET /transfers/{id} ---

    public function testShowReturns200WithAllFields(): void
    {
        $transfer = $this->makeTransfer();

        $service = $this->createStub(TransferService::class);
        $service->method('getTransfer')->willReturn($transfer);

        $response = (new TransferController($service))->show($transfer->getId());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame($transfer->getId(), $data['transfer_id']);
        $this->assertSame(1, $data['source_account_id']);
        $this->assertSame(2, $data['dest_account_id']);
        $this->assertSame(1500, $data['amount']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('reserved', $data['status']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $service = $this->createStub(TransferService::class);
        $service->method('getTransfer')->willReturn(null);

        $response = (new TransferController($service))->show('nonexistent-id');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // --- GET /accounts/{id}/balance ---

    public function testBalanceReturns200WithAccountData(): void
    {
        $account = new Account('Alice', 8500, 'EUR');
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($account, 1);

        $service = $this->createStub(TransferService::class);
        $service->method('getAccount')->willReturn($account);

        $response = (new TransferController($service))->balance(1);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(1, $data['account_id']);
        $this->assertSame('Alice', $data['holder_name']);
        $this->assertSame(8500, $data['balance']);
        $this->assertSame('EUR', $data['currency']);
    }

    public function testBalanceReturns404WhenNotFound(): void
    {
        $service = $this->createStub(TransferService::class);
        $service->method('getAccount')->willReturn(null);

        $response = (new TransferController($service))->balance(9999);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
