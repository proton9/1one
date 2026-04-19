<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Webhook;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Infrastructure\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WebhookDispatcherTest extends TestCase
{
    private function makeDispatcher(HttpClientInterface $httpClient): WebhookDispatcher
    {
        return new WebhookDispatcher($httpClient, 'test-secret');
    }

    private function makeTransfer(): Transfer
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);

        $transfer = new Transfer($source, $dest, 2500, 'http://merchant.example.com/webhook');
        $transfer->markDone();

        return $transfer;
    }

    private function stubResponse(int $statusCode): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);

        return $response;
    }

    public function testDispatchSendsPostWithFormEncodedBody(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://merchant.example.com/webhook',
                $this->callback(function (array $options) {
                    return str_contains($options['headers']['Content-Type'], 'application/x-www-form-urlencoded')
                        && isset($options['body']);
                }),
            )
            ->willReturn($this->stubResponse(200));

        $this->makeDispatcher($httpClient)->dispatch($transfer);
    }

    public function testContentTypeIsFormUrlEncoded(): void
    {
        $transfer = $this->makeTransfer();

        $capturedOptions = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return $this->stubResponse(200);
            });

        $this->makeDispatcher($httpClient)->dispatch($transfer);

        $this->assertSame('application/x-www-form-urlencoded', $capturedOptions['headers']['Content-Type']);
    }

    public function testWebhookSignatureIsValidHmac(): void
    {
        $transfer = $this->makeTransfer();

        $capturedOptions = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return $this->stubResponse(200);
            });

        $this->makeDispatcher($httpClient)->dispatch($transfer);

        $body = $capturedOptions['body'];
        $expectedSignature = 'sha256=' . base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $this->assertSame($expectedSignature, $capturedOptions['headers']['X-Webhook-Signature']);
    }

    public function testPayloadContainsRequiredFields(): void
    {
        $transfer = $this->makeTransfer();

        $capturedBody = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedBody) {
                $capturedBody = $options['body'];

                return $this->stubResponse(200);
            });

        $this->makeDispatcher($httpClient)->dispatch($transfer);

        parse_str($capturedBody, $data);
        $this->assertSame($transfer->getId(), $data['transfer_id']);
        $this->assertSame('done', $data['status']);
        $this->assertSame('2500', $data['amount']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertArrayHasKey('date', $data);
    }

    public function testSuccessfulResponseDoesNotThrow(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->stubResponse(200));

        // Should not throw
        $this->makeDispatcher($httpClient)->dispatch($transfer);
        $this->addToAssertionCount(1);
    }

    public function test4xxResponseThrowsRuntimeException(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->stubResponse(404));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed with HTTP 404');

        $this->makeDispatcher($httpClient)->dispatch($transfer);
    }

    public function test5xxResponseThrowsRuntimeException(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->stubResponse(503));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed with HTTP 503');

        $this->makeDispatcher($httpClient)->dispatch($transfer);
    }
}
