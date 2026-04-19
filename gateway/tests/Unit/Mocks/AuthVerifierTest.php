<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mocks;

use PHPUnit\Framework\TestCase;

// The shared verifier lives outside the gateway codebase but is exercised here
// so the CI unit-test job guards its correctness.
require_once __DIR__ . '/../../../../mocks/AuthVerifier.php';

use Mocks\AuthVerifier;

class AuthVerifierTest extends TestCase
{
    private const KEY = 'test-key';
    private const HOST = 'mock-provider';
    private const PORT = '8001';

    private function buildMacHeader(string $method, string $uri, string $body): string
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $normalized = implode("\n", [$ts, $nonce, $method, $uri, self::HOST, self::PORT, $body, '']);
        $mac = base64_encode(hash_hmac('sha256', $normalized, self::KEY, true));

        return sprintf('MAC id="client", ts="%s", nonce="%s", mac="%s"', $ts, $nonce, $mac);
    }

    public function testVerifyMacAcceptsValidHeader(): void
    {
        $body = '{"transfer_id":"abc"}';
        $header = $this->buildMacHeader('POST', '/process', $body);

        $this->assertTrue(
            AuthVerifier::verifyMac($header, 'POST', '/process', $body, self::KEY, self::HOST, self::PORT),
        );
    }

    public function testVerifyMacRejectsWrongKey(): void
    {
        $body = '{}';
        $header = $this->buildMacHeader('POST', '/process', $body);

        $this->assertFalse(
            AuthVerifier::verifyMac($header, 'POST', '/process', $body, 'wrong-key', self::HOST, self::PORT),
        );
    }

    public function testVerifyMacRejectsTamperedBody(): void
    {
        $header = $this->buildMacHeader('POST', '/process', '{"a":1}');

        $this->assertFalse(
            AuthVerifier::verifyMac($header, 'POST', '/process', '{"a":2}', self::KEY, self::HOST, self::PORT),
        );
    }

    public function testVerifyMacRejectsStaleTimestamp(): void
    {
        $staleTs = (string) (time() - 400);
        $nonce = 'aaaa';
        $body = '';
        $normalized = implode("\n", [$staleTs, $nonce, 'POST', '/x', self::HOST, self::PORT, $body, '']);
        $mac = base64_encode(hash_hmac('sha256', $normalized, self::KEY, true));
        $header = sprintf('MAC id="c", ts="%s", nonce="%s", mac="%s"', $staleTs, $nonce, $mac);

        $this->assertFalse(
            AuthVerifier::verifyMac($header, 'POST', '/x', $body, self::KEY, self::HOST, self::PORT),
        );
    }

    public function testVerifyMacRejectsMissingPrefix(): void
    {
        $this->assertFalse(
            AuthVerifier::verifyMac('Basic xyz', 'POST', '/', '', self::KEY, self::HOST, self::PORT),
        );
    }

    public function testVerifyMacRejectsHeaderMissingMac(): void
    {
        $this->assertFalse(
            AuthVerifier::verifyMac('MAC id="c", ts="123"', 'POST', '/', '', self::KEY, self::HOST, self::PORT),
        );
    }

    public function testVerifyWebhookSignatureAcceptsValid(): void
    {
        $body = 'transfer_id=abc&status=done';
        $sig = 'sha256=' . base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->assertTrue(AuthVerifier::verifyWebhookSignature($body, $sig, 'secret'));
    }

    public function testVerifyWebhookSignatureRejectsWrongSecret(): void
    {
        $body = 'x=1';
        $sig = 'sha256=' . base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->assertFalse(AuthVerifier::verifyWebhookSignature($body, $sig, 'other-secret'));
    }

    public function testVerifyWebhookSignatureRejectsTamperedBody(): void
    {
        $sig = 'sha256=' . base64_encode(hash_hmac('sha256', 'original', 'secret', true));

        $this->assertFalse(AuthVerifier::verifyWebhookSignature('tampered', $sig, 'secret'));
    }

    public function testVerifyWebhookSignatureRejectsMissingPrefix(): void
    {
        $body = 'x=1';
        $raw = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->assertFalse(AuthVerifier::verifyWebhookSignature($body, $raw, 'secret'));
    }

    public function testVerifyWebhookSignatureRejectsEmpty(): void
    {
        $this->assertFalse(AuthVerifier::verifyWebhookSignature('x', '', 'secret'));
    }
}
