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
     * Return the stored transfer ID for this key, or null if the key is unused.
     */
    public function check(string $key): ?string
    {
        $result = $this->redis->get(self::PREFIX . $key);

        return $result !== false ? $result : null;
    }

    /**
     * Atomically reserve this key for `$transferId`. Returns true if this caller won
     * the reservation, false if the key was already held by someone else.
     *
     * This is the single source of truth for idempotency: only one caller can reserve
     * a key, and reservation happens before the transfer is persisted so duplicate
     * transfers cannot be created even under concurrent requests.
     */
    public function reserve(string $key, string $transferId): bool
    {
        return $this->redis->setNxEx(self::PREFIX . $key, $transferId, self::TTL_SECONDS);
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
