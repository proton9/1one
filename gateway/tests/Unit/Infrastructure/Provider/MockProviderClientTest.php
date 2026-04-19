<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Provider;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Infrastructure\Provider\MockProviderClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MockProviderClientTest extends TestCase
{
    private function makeClient(HttpClientInterface $httpClient): MockProviderClient
    {
        return new MockProviderClient(
            $httpClient,
            'http://mock-provider:8001',
            'gateway-app',
            'secret-mac-key',
        );
    }

    private function makeTransfer(): Transfer
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($source, 1);
        $ref->setValue($dest, 2);

        return new Transfer($source, $dest, 1500);
    }

    private function stubResponse(int $statusCode, array $body = []): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($body);

        return $response;
    }

    public function testProcessTransferSendsPostToCorrectUrl(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://mock-provider:8001/process',
                $this->callback(function (array $options) {
                    return isset($options['headers']['Content-Type'])
                        && $options['headers']['Content-Type'] === 'application/json'
                        && isset($options['body']);
                }),
            )
            ->willReturn($this->stubResponse(200, ['status' => 'completed']));

        $this->makeClient($httpClient)->processTransfer($transfer);
    }

    public function testMacAuthorizationHeaderHasCorrectFormat(): void
    {
        $transfer = $this->makeTransfer();

        $capturedOptions = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return $this->stubResponse(200, ['status' => 'completed']);
            });

        $this->makeClient($httpClient)->processTransfer($transfer);

        $authHeader = $capturedOptions['headers']['Authorization'];
        $this->assertMatchesRegularExpression(
            '/^MAC id="gateway-app", ts="\d+", nonce="[a-f0-9]+", mac="[A-Za-z0-9+\/=]+"$/',
            $authHeader,
        );
    }

    public function testMacSignatureIsValidHmac(): void
    {
        $transfer = $this->makeTransfer();

        $capturedOptions = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return $this->stubResponse(200, ['status' => 'completed']);
            });

        $this->makeClient($httpClient)->processTransfer($transfer);

        $authHeader = $capturedOptions['headers']['Authorization'];
        preg_match_all('/(\w+)="([^"]*)"/', $authHeader, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $match) {
            $params[$match[1]] = $match[2];
        }

        $normalizedString = implode("\n", [
            $params['ts'], $params['nonce'], 'POST', '/process',
            'mock-provider', '8001', $capturedOptions['body'], '',
        ]);
        $expectedMac = base64_encode(hash_hmac('sha256', $normalizedString, 'secret-mac-key', true));

        $this->assertSame($expectedMac, $params['mac']);
    }

    public function testSuccessfulResponseReturnsDecodedArray(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->stubResponse(200, ['status' => 'completed', 'transfer_id' => 'abc']));

        $result = $this->makeClient($httpClient)->processTransfer($transfer);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('abc', $result['transfer_id']);
    }

    public function testErrorResponseReturnsFailedStatus(): void
    {
        $transfer = $this->makeTransfer();

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->stubResponse(500));

        $result = $this->makeClient($httpClient)->processTransfer($transfer);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('500', $result['reason']);
    }

    public function testRequestBodyContainsTransferFields(): void
    {
        $transfer = $this->makeTransfer();

        $capturedBody = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedBody) {
                $capturedBody = json_decode($options['body'], true);

                return $this->stubResponse(200, ['status' => 'completed']);
            });

        $this->makeClient($httpClient)->processTransfer($transfer);

        $this->assertSame($transfer->getId(), $capturedBody['transfer_id']);
        $this->assertSame(1500, $capturedBody['amount']);
        $this->assertSame('EUR', $capturedBody['currency']);
        $this->assertSame(1, $capturedBody['source_account']);
        $this->assertSame(2, $capturedBody['dest_account']);
    }

    public function testParsesHostAndPortFromBaseUrl(): void
    {
        // The client is constructed with 'http://mock-provider:8001'
        // Verify the MAC normalized string uses 'mock-provider' and '8001'
        $transfer = $this->makeTransfer();

        $capturedOptions = null;
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return $this->stubResponse(200, ['status' => 'completed']);
            });

        $this->makeClient($httpClient)->processTransfer($transfer);

        // Verify by checking the MAC is valid (which requires correct host/port parsing)
        $authHeader = $capturedOptions['headers']['Authorization'];
        preg_match_all('/(\w+)="([^"]*)"/', $authHeader, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $match) {
            $params[$match[1]] = $match[2];
        }

        $normalizedString = implode("\n", [
            $params['ts'], $params['nonce'], 'POST', '/process',
            'mock-provider', '8001', $capturedOptions['body'], '',
        ]);
        $expectedMac = base64_encode(hash_hmac('sha256', $normalizedString, 'secret-mac-key', true));

        $this->assertSame($expectedMac, $params['mac'], 'MAC validation proves correct host/port parsing');
    }
}
