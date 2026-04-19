<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Transfer\Transfer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MockProviderClient implements ProviderClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $macId,
        private string $macKey,
    ) {}

    public function processTransfer(Transfer $transfer): array
    {
        $uri = '/process';
        $body = json_encode([
            'transfer_id' => $transfer->getId(),
            'amount' => $transfer->getAmount(),
            'currency' => $transfer->getCurrency(),
            'source_account' => $transfer->getSourceAccount()->getId(),
            'dest_account' => $transfer->getDestAccount()->getId(),
        ]);

        $authHeader = $this->buildMacHeader('POST', $uri, $body);

        $response = $this->httpClient->request('POST', $this->baseUrl . $uri, [
            'headers' => [
                'Authorization' => $authHeader,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);

        if ($response->getStatusCode() >= 400) {
            return [
                'status' => 'failed',
                'reason' => 'Provider returned HTTP ' . $response->getStatusCode(),
            ];
        }

        return $response->toArray();
    }

    /**
     * Build MAC Authorization header.
     *
     * Format: MAC id="<mac_id>", ts="<timestamp>", nonce="<nonce>", mac="<signature>"
     * Signature = Base64(HMAC-SHA256(key, ts\nnonce\nmethod\nuri\nhost\nport\nbody\n))
     */
    private function buildMacHeader(string $method, string $uri, string $body): string
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $parsed = parse_url($this->baseUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = (string) ($parsed['port'] ?? 80);

        $normalizedString = implode("\n", [$ts, $nonce, $method, $uri, $host, $port, $body, '']);

        $mac = base64_encode(hash_hmac('sha256', $normalizedString, $this->macKey, true));

        return sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"', $this->macId, $ts, $nonce, $mac);
    }
}
