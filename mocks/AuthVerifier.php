<?php

declare(strict_types=1);

namespace Mocks;

/**
 * Shared HMAC helpers used by both mock-provider (MAC auth on incoming API calls)
 * and mock-merchant (webhook signature on incoming webhooks). Kept in one place so
 * the two mocks agree bit-for-bit on the signing formats.
 */
final class AuthVerifier
{
    /**
     * Verify a MAC Authorization header.
     *
     * Signature format:
     *   MAC id="...", ts="...", nonce="...", mac="..."
     * where mac = base64(hmac_sha256(key, ts\nnonce\nmethod\nuri\nhost\nport\nbody\n)).
     *
     * Returns true only if the header parses, the timestamp is within $clockSkewSeconds,
     * and the MAC matches in constant time.
     */
    public static function verifyMac(
        string $header,
        string $method,
        string $uri,
        string $body,
        string $key,
        string $host,
        string $port,
        int $clockSkewSeconds = 300,
    ): bool {
        if (!preg_match('/^MAC\s+/', $header)) {
            return false;
        }

        $params = self::parseMacParams($header);
        if (!isset($params['ts'], $params['nonce'], $params['mac'])) {
            return false;
        }

        if (abs(time() - (int) $params['ts']) > $clockSkewSeconds) {
            return false;
        }

        $normalized = implode("\n", [
            $params['ts'], $params['nonce'], $method, $uri, $host, $port, $body, '',
        ]);
        $expected = base64_encode(hash_hmac('sha256', $normalized, $key, true));

        return hash_equals($expected, $params['mac']);
    }

    /**
     * Verify a webhook signature in the format `sha256=<base64(hmac_sha256(body, secret))>`.
     */
    public static function verifyWebhookSignature(string $body, string $received, string $secret): bool
    {
        $expected = 'sha256=' . base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($expected, $received);
    }

    /**
     * @return array<string, string>
     */
    private static function parseMacParams(string $header): array
    {
        $params = [];
        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $params[$match[1]] = $match[2];
        }

        return $params;
    }
}
