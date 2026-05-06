<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Infrastructure\RedisAdapterInterface;
use App\Service\IdempotencyRecord;
use App\Service\IdempotencyService;
use PHPUnit\Framework\TestCase;

class IdempotencyServiceTest extends TestCase
{
    public function testCheckDecodesEnvelopeIntoRecord(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn('{"id":"transfer-uuid-123","fp":"abc"}');

        $record = (new IdempotencyService($redis))->check('test-key');

        $this->assertInstanceOf(IdempotencyRecord::class, $record);
        $this->assertSame('transfer-uuid-123', $record->transferId);
        $this->assertSame('abc', $record->fingerprint);
    }

    public function testCheckReturnsNullWhenKeyDoesNotExist(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn(false);

        $this->assertNull((new IdempotencyService($redis))->check('missing-key'));
    }

    public function testCheckTreatsLegacyBareStringAsRecordWithNullFingerprint(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn('legacy-uuid');

        $record = (new IdempotencyService($redis))->check('legacy-key');

        $this->assertNotNull($record);
        $this->assertSame('legacy-uuid', $record->transferId);
        $this->assertNull($record->fingerprint);
    }

    public function testCheckTreatsEnvelopeWithoutFingerprintAsNull(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('get')->willReturn('{"id":"transfer-uuid-123","fp":null}');

        $record = (new IdempotencyService($redis))->check('test-key');

        $this->assertSame('transfer-uuid-123', $record->transferId);
        $this->assertNull($record->fingerprint);
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

    public function testReserveSerializesEnvelopeWithFingerprint(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('setNxEx')
            ->with(
                'idempotency:new-key',
                '{"id":"transfer-123","fp":"fp-hash"}',
                86400,
            )
            ->willReturn(true);

        $this->assertTrue(
            (new IdempotencyService($redis))->reserve('new-key', 'transfer-123', 'fp-hash'),
        );
    }

    public function testReserveDefaultsFingerprintToNull(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('setNxEx')
            ->with(
                'idempotency:new-key',
                '{"id":"transfer-123","fp":null}',
                86400,
            )
            ->willReturn(true);

        (new IdempotencyService($redis))->reserve('new-key', 'transfer-123');
    }

    public function testReserveReturnsFalseWhenKeyAlreadyHeld(): void
    {
        $redis = $this->createStub(RedisAdapterInterface::class);
        $redis->method('setNxEx')->willReturn(false);

        $this->assertFalse(
            (new IdempotencyService($redis))->reserve('taken-key', 'transfer-456', 'fp'),
        );
    }

    public function testReserveUsesNamespacedKey(): void
    {
        $redis = $this->createMock(RedisAdapterInterface::class);
        $redis->expects($this->once())
            ->method('setNxEx')
            ->with('idempotency:xyz', $this->isString(), 86400)
            ->willReturn(true);

        (new IdempotencyService($redis))->reserve('xyz', 'some-id', 'fp');
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
