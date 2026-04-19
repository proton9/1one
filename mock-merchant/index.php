<?php

declare(strict_types=1);

/**
 * Mock Merchant Server
 *
 * Simulates a merchant that receives webhook callbacks from the gateway.
 * Logs incoming webhooks and verifies HMAC signatures.
 */

require_once __DIR__ . '/AuthVerifier.php';

use Mocks\AuthVerifier;

$webhookSecret = getenv('WEBHOOK_SECRET') ?: 'webhook-signing-secret';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

header('Content-Type: application/json');

if ($uri === '/health' && $method === 'GET') {
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($uri === '/webhook' && $method === 'POST') {
    $body = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

    if (!AuthVerifier::verifyWebhookSignature($body, $signature, $webhookSecret)) {
        error_log('[mock-merchant] WARNING: Invalid webhook signature');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    parse_str($body, $data);
    error_log(sprintf(
        '[mock-merchant] Webhook received: transfer_id=%s status=%s amount=%s date=%s',
        $data['transfer_id'] ?? '?',
        $data['status'] ?? '?',
        $data['amount'] ?? '?',
        $data['date'] ?? '?',
    ));

    echo json_encode(['received' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
