<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Domain\Transfer\Transfer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookDispatcher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $secret,
    ) {}

    /**
     * Dispatch a webhook notification to the merchant's callback URL.
     *
     * Payload is form-encoded (not JSON) to match callback format.
     * Signed with HMAC-SHA256 via X-Webhook-Signature header.
     *
     * @throws \RuntimeException on non-2xx response
     */
    public function dispatch(Transfer $transfer): void
    {
        $payload = http_build_query([
            'transfer_id' => $transfer->getId(),
            'status' => $transfer->getStatus()->value,
            'amount' => $transfer->getAmount(),
            'currency' => $transfer->getCurrency(),
            'date' => $transfer->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);

        $signature = 'sha256=' . base64_encode(
            hash_hmac('sha256', $payload, $this->secret, true),
        );

        $response = $this->httpClient->request('POST', $transfer->getCallbackUrl(), [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Webhook-Signature' => $signature,
            ],
            'body' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                sprintf('Webhook delivery failed with HTTP %d', $statusCode),
            );
        }
    }
}
