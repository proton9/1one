<?php

declare(strict_types=1);

namespace App\Infrastructure;

class RedisAdapter implements RedisAdapterInterface
{
    private \Redis $redis;

    public function __construct(string $dsn)
    {
        $this->redis = new \Redis();
        $parsed = parse_url($dsn);
        $this->redis->connect($parsed['host'] ?? '127.0.0.1', $parsed['port'] ?? 6379);
    }

    public function get(string $key): string|false
    {
        return $this->redis->get($key);
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->redis->setex($key, $ttl, $value);
    }

    public function setNxEx(string $key, string $value, int $ttl): bool
    {
        return (bool) $this->redis->set($key, $value, ['NX', 'EX' => $ttl]);
    }

    public function del(string $key): int
    {
        return (int) $this->redis->del($key);
    }
}
