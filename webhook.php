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

    // Determina o tipo de mensagem com base nos flags fromMe e fromApi
    $messageType = 'incoming'; // Padrão para mensagens recebidas de terceiros

    // Prepara dados adicionais para a mensagem
    $additionalData = [];

    // Adiciona o messageId se disponível
    if (isset($data['messageId'])) {
        $additionalData['messageId'] = $data['messageId'];
    }

    // Se a mensagem foi enviada pelo usuário diretamente do WhatsApp (não pela API)
    if (isset($data['fromMe']) && $data['fromMe'] && !(isset($data['fromApi']) && $data['fromApi'])) {
        $messageType = 'outgoing'; // Mensagens enviadas pelo usuário devem aparecer como saída
        Logger::log('info', 'Message sent directly from WhatsApp (not through API)', [
            'fromMe' => $data['fromMe'] ?? false,
            'fromApi' => $data['fromApi'] ?? false,
            'message_type' => $messageType,
            'messageId' => $data['messageId'] ?? 'not set'
        ]);
    }

    $chatwoot->sendMessage($phone, $message, $attachments, $messageType, $additionalData);
}

function handleChatwootMessage($data)
{
    // Log detalhado para debug
    Logger::log('debug', 'Chatwoot message details', [
        'message_type' => $data['message_type'] ?? 'not set',
        'private' => $data['private'] ?? 'not set',
        'content' => $data['content'] ?? 'not set',
        'content_attributes' => $data['content_attributes'] ?? 'not set',
        'source_id' => $data['source_id'] ?? 'not set',
        'has_content_attributes' => isset($data['content_attributes']),
        'content_attributes_type' => isset($data['content_attributes']) ? gettype($data['content_attributes']) : 'not set',
        'additional_attributes' => $data['additional_attributes'] ?? 'not set',
        'has_additional_attributes' => isset($data['additional_attributes']),
        'additional_attributes_type' => isset($data['additional_attributes']) ? gettype($data['additional_attributes']) : 'not set',
        'additional_attributes_json' => isset($data['additional_attributes']) ? json_encode($data['additional_attributes']) : 'not set'
    ]);

    // Processa apenas mensagens de saída não-privadas
    if ($data['message_type'] !== 'outgoing' || ($data['private'] ?? false)) {
        return;
    }

    // Verifica se a mensagem tem o atributo 'source' com valor 'whatsapp_direct'
    // Isso indica que a mensagem foi enviada diretamente pelo WhatsApp e não deve ser reenviada
    if (isset($data['content_attributes']) &&
        is_array($data['content_attributes']) &&
        isset($data['content_attributes']['source']) &&
        $data['content_attributes']['source'] === 'whatsapp_direct') {

        Logger::log('info', 'Ignoring message sent directly from WhatsApp', [
            'message' => $data['content'] ?? '',
            'source' => $data['content_attributes']['source']
        ]);
        return;
    }

    // Verifica se a mensagem tem o messageId do WhatsApp
    // Se tiver, significa que é uma mensagem que veio do WhatsApp e não deve ser reenviada
    if (isset($data['additional_attributes']) &&
        is_array($data['additional_attributes']) &&
        isset($data['additional_attributes']['messageId'])) {

        Logger::log('info', 'Ignoring message with WhatsApp messageId', [
            'message' => $data['content'] ?? '',
            'messageId' => $data['additional_attributes']['messageId']
        ]);
        return;
    }

    // Verifica se a mensagem tem o atributo 'source_id' nulo
    // Isso pode indicar que a mensagem foi enviada diretamente do Chatwoot
    if (empty($data['source_id'])) {
        Logger::log('info', 'Message has no source_id, likely sent from Chatwoot', [
            'message' => $data['content'] ?? ''
        ]);
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
