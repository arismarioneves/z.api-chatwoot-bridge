<?php

include 'vendor/autoload.php';
include 'config.php';

use WhatsappBridge\Logger;
use WhatsappBridge\WebhookHandler;

// Configura headers
header('Content-Type: application/json');

// Verifica o método da requisição
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Recebe o payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log do payload recebido
Logger::log('info', 'Raw webhook received', ['payload' => $data]);

// Valida o payload
if (!$data) {
    Logger::log('error', 'Invalid payload', ['input' => $input]);
    http_response_code(400);
    die(json_encode(['error' => 'Invalid payload']));
}

try {
    $handler = new WebhookHandler();
    $result = $handler->handle($data);

    echo json_encode(['status' => 'success', 'result' => $result]);
} catch (Exception $e) {
    Logger::log('error', 'Webhook processing failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
