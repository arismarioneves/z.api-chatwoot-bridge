<?php

include 'vendor/autoload.php';
include 'config.php';

use WhatsappBridge\Logger;
use WhatsappBridge\ZAPIHandler;
use WhatsappBridge\ChatwootHandler;

// Configura headers
header('Content-Type: application/json');

// Verifica o método da requisição
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // http_response_code(405);
    // die(json_encode(['error' => 'Webhook :]']));
    die(json_encode(['status' => 'success', 'message' => 'Webhook :]']));
}

// Recebe o payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Se for um grupo, ignora a mensagem
if ($data['isGroup']) {
    exit;
}

// Log do payload recebido
Logger::log('info', 'Raw webhook received', ['payload' => $data]);

// Valida o payload
if (!$data) {
    Logger::log('error', 'Invalid payload', ['input' => $input]);
    http_response_code(400);
    die(json_encode(['error' => 'Invalid payload']));
}

try {
    // Identificar origem do webhook
    if (isset($data['event'])) {
        $event = $data['event'];

        Logger::log('info', 'Handling Chatwoot event', ['event' => $event]);

        switch ($event) {
            case 'message_created':
                handleChatwootMessage($data);
                break;
            case 'conversation_created':
            case 'contact_updated':
            case 'conversation_updated':
            case 'message_updated':
                // Ignorar estes eventos
                echo json_encode(['status' => 'ignored']);
                break;
            default:
                Logger::log('info', 'Unhandled Chatwoot event', ['event' => $event]);
                break;
        }
    } elseif (isset($data['phone']) && isset($data['type'])) {
        handleZAPIWebhook($data);
    } else {
        throw new Exception('Unrecognized webhook format');
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    Logger::log('error', 'Webhook processing failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleZAPIWebhook($data)
{
    // Verifica se a mensagem foi enviada pelo sistema através da API
    // Ignora apenas mensagens enviadas pelo próprio sistema através da API
    if (isset($data['fromMe']) && $data['fromMe'] && isset($data['fromApi']) && $data['fromApi']) {
        Logger::log('info', 'Ignoring message sent by the system through API', [
            'fromMe' => $data['fromMe'] ?? false,
            'fromApi' => $data['fromApi'] ?? false
        ]);
        return;
    }

    // Processa mensagens enviadas diretamente pelo WhatsApp (fromMe=true, fromApi=false)
    // ou mensagens recebidas de terceiros (fromMe=false)
    $chatwoot = new ChatwootHandler();
    $phone = $data['phone'] ?? '';
    $message = $data['text']['message'] ?? $data['message'] ?? '';

    // Processa anexos se houver
    $attachments = [];
    if (isset($data['media'])) {
        $attachments[] = [
            'url' => $data['media'],
            'type' => $data['type'] ?? 'file'
        ];
    }

    if (empty($message) && !empty($attachments)) {
        $message = '[Mídia enviada]';
    }

    $chatwoot->sendMessage($phone, $message, $attachments);
}

function handleChatwootMessage($data)
{
    // Processa apenas mensagens de saída não-privadas
    if ($data['message_type'] !== 'outgoing' || ($data['private'] ?? false)) {
        return;
    }

    $zapi = new ZAPIHandler();
    $phone = $data['conversation']['contact_inbox']['source_id'] ?? '';
    $message = $data['content'] ?? '';

    if (empty($phone) || empty($message)) {
        Logger::log('error', 'Missing required data for sending message');
        return;
    }

    $zapi->sendMessage($phone, $message);
}
