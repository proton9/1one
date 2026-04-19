<?php

declare(strict_types=1);

/**
 * Mock Payment Provider
 *
 * Simulates an upstream payment processor that the gateway calls.
 * Verifies MAC authentication headers and returns success/failure.
 */

require_once __DIR__ . '/AuthVerifier.php';

use Mocks\AuthVerifier;

$macKey = getenv('PROVIDER_MAC_KEY') ?: 'secret-mac-key';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

header('Content-Type: application/json');

if ($uri === '/health' && $method === 'GET') {
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($uri === '/process' && $method === 'POST') {
    $body = file_get_contents('php://input');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!AuthVerifier::verifyMac($authHeader, $method, $uri, $body, $macKey, 'mock-provider', '8001')) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid MAC authentication']);
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['transfer_id']) || !is_string($data['transfer_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }

    error_log(sprintf(
        '[mock-provider] Processing transfer %s: %d %s',
        $data['transfer_id'],
        $data['amount'] ?? 0,
        $data['currency'] ?? 'EUR',
    ));

    echo json_encode([
        'status' => 'completed',
        'transfer_id' => $data['transfer_id'],
        'provider_reference' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
