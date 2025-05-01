<?php

// Define BASE_PATH para facilitar a inclusão de arquivos
define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config.php';

use WhatsappBridge\Logger;
use WhatsappBridge\WebhookHandler;

// Configura headers
header('Content-Type: application/json');

// Verifica o método da requisição
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    die(json_encode(['error' => 'Method Not Allowed. Only POST is accepted.']));
}

// Recebe o payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Valida o payload JSON inicial
if (json_last_error() !== JSON_ERROR_NONE) {
    Logger::log('error', 'Invalid JSON payload received', ['input' => $input, 'json_error' => json_last_error_msg()]);
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON payload: ' . json_last_error_msg()]));
}

// Se não for um array após o decode, é inválido
if (!is_array($data)) {
    Logger::log('error', 'Payload is not a valid JSON object/array', ['input' => $input]);
    http_response_code(400);
    die(json_encode(['error' => 'Invalid payload format.']));
}

// Se for uma mensagem de grupo do Z-API, ignora
if (isset($data['isGroup']) && $data['isGroup'] === true) {
    Logger::log('info', 'Ignoring group message from Z-API', ['payload_keys' => array_keys($data)]);
    echo json_encode(['status' => 'ignored', 'reason' => 'Group message']);
    exit;
}

try {
    // Instancia o handler principal
    $handler = new WebhookHandler();

    // Delega o processamento para o handler
    $processed = $handler->handle($data);

    if ($processed) {
        echo json_encode(['status' => 'success']);
    } else {
        // Se handle retornar false, significa que foi ignorado intencionalmente
        echo json_encode(['status' => 'ignored']);
    }
} catch (\InvalidArgumentException $e) {
    // Erro específico para formato não reconhecido ou dados faltando
    Logger::log('warning', 'Webhook processing failed: Invalid Argument', ['error' => $e->getMessage(), 'payload_keys' => array_keys($data)]);
    http_response_code(400); // Bad Request
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    // Erros gerais durante o processamento
    Logger::log('error', 'Webhook processing failed: General Exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(), // Loga o stack trace para debug
        'payload_keys' => array_keys($data)
    ]);
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Internal Server Error: ' . $e->getMessage()]);
}
