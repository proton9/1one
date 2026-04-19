<?php

declare(strict_types=1);

namespace App\Infrastructure;

interface RedisAdapterInterface
{
    public function get(string $key): string|false;

    public function setex(string $key, int $ttl, string $value): bool;

    /**
     * Atomic SET IF NOT EXISTS with TTL (Redis `SET key value NX EX ttl`).
     * Returns true if the key was newly set, false if it already existed.
     */
    public function setNxEx(string $key, string $value, int $ttl): bool;

    public function del(string $key): int;
}
