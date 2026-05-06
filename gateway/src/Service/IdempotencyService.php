<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\RedisAdapterInterface;

class IdempotencyService
{
    private const TTL_SECONDS = 86400; // 24 hours
    private const PREFIX = 'idempotency:';

    public function __construct(
        private RedisAdapterInterface $redis,
    ) {}

    /**
     * Return the stored record (transfer ID + payload fingerprint) for this key,
     * or null if the key is unused.
     *
     * Bare-string values written before payload fingerprinting was introduced
     * decode to a record with fingerprint=null, so callers must treat null
     * fingerprints as "unverifiable" and skip the comparison.
     */
    public function check(string $key): ?IdempotencyRecord
    {
        $raw = $this->redis->get(self::PREFIX . $key);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, associative: true);
        if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
            $fingerprint = isset($decoded['fp']) && is_string($decoded['fp']) ? $decoded['fp'] : null;

            return new IdempotencyRecord($decoded['id'], $fingerprint);
        }

        return new IdempotencyRecord($raw, null);
    }

    /**
     * Atomically reserve this key for `$transferId` together with the payload
     * fingerprint. Returns true if this caller won the reservation, false if
     * the key was already held by someone else.
     *
     * This is the single source of truth for idempotency: only one caller can reserve
     * a key, and reservation happens before the transfer is persisted so duplicate
     * transfers cannot be created even under concurrent requests.
     */
    public function reserve(string $key, string $transferId, ?string $fingerprint = null): bool
    {
        $envelope = json_encode(
            ['id' => $transferId, 'fp' => $fingerprint],
            JSON_THROW_ON_ERROR,
        );

        return $this->redis->setNxEx(self::PREFIX . $key, $envelope, self::TTL_SECONDS);
    }

    /**
     * Release a reservation — call this when the caller-that-won later rolls back,
     * so subsequent retries with the same key can succeed.
     */
    public function release(string $key): void
    {
        $this->redis->del(self::PREFIX . $key);
    }
}
