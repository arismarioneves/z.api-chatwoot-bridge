<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use WhatsappBridge\Logger;
use WhatsappBridge\WebhookHandler;

header('Content-Type: application/json');

// Verificar método
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    die(json_encode(['message' => 'Zapiwoot Webhook :)']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method Not Allowed']));
}

// Processar payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON payload']));
}

// Ignorar mensagens de grupo
if (!empty($data['isGroup'])) {
    die(json_encode(['status' => 'ignored', 'reason' => 'Group message']));
}

try {
    $handler = new WebhookHandler();
    $processed = $handler->handle($data);
    echo json_encode(['status' => $processed ? 'success' : 'ignored']);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    Logger::log('error', 'Webhook error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
