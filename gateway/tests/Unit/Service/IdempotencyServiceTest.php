<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Infrastructure\RedisAdapterInterface;
use App\Service\IdempotencyService;
use PHPUnit\Framework\TestCase;

class IdempotencyServiceTest extends TestCase
{
    public function testCheckReturnsTransferIdWhenKeyExists(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn('transfer-uuid-123');

        $service = new IdempotencyService($redis);

        $this->assertSame('transfer-uuid-123', $service->check('test-key'));
    }

    public function testCheckReturnsNullWhenKeyDoesNotExist(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn(false);

        $service = new IdempotencyService($redis);

        $this->assertNull($service->check('missing-key'));
    }

    public function testCheckPrefixesKeyWithNamespace(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('get')
            ->with('idempotency:abc')
            ->willReturn(false);

        (new IdempotencyService($redis))->check('abc');
    }

    public function testReserveReturnsTrueWhenKeyNewlyReserved(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('setNxEx')
            ->with('idempotency:new-key', 'transfer-123', 86400)
            ->willReturn(true);

        $this->assertTrue((new IdempotencyService($redis))->reserve('new-key', 'transfer-123'));
    }

    public function testReserveReturnsFalseWhenKeyAlreadyHeld(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('setNxEx')->willReturn(false);

        $this->assertFalse((new IdempotencyService($redis))->reserve('taken-key', 'transfer-456'));
    }

    public function testReserveUsesNamespacedKey(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('setNxEx')
            ->with('idempotency:xyz', $this->isString(), 86400)
            ->willReturn(true);

        (new IdempotencyService($redis))->reserve('xyz', 'some-id');
    }

    public function testReleaseCallsDelWithNamespacedKey(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('del')
            ->with('idempotency:release-me')
            ->willReturn(1);

        (new IdempotencyService($redis))->release('release-me');
    }
}
