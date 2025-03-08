<?php

include 'vendor/autoload.php';
include 'config.php';

use WhatsappBridge\Logger;
use WhatsappBridge\ZAPIHandler;
use WhatsappBridge\ChatwootHandler;

// Função para verificar se uma mensagem já foi processada
function isMessageProcessed($messageId) {
    $cacheFile = __DIR__ . '/logs/processed_messages.json';

    // Cria o arquivo se não existir
    if (!file_exists($cacheFile)) {
        file_put_contents($cacheFile, json_encode([]));
        return false;
    }

    // Lê o cache
    $processedMessages = json_decode(file_get_contents($cacheFile), true);

    // Verifica se a mensagem já foi processada
    return isset($processedMessages[$messageId]);
}

// Função para marcar uma mensagem como processada
function markMessageAsProcessed($messageId, $data = []) {
    $cacheFile = __DIR__ . '/logs/processed_messages.json';

    // Lê o cache atual
    $processedMessages = [];
    if (file_exists($cacheFile)) {
        $processedMessages = json_decode(file_get_contents($cacheFile), true);
    }

    // Adiciona a mensagem ao cache
    $processedMessages[$messageId] = [
        'timestamp' => time(),
        'data' => $data
    ];

    // Limpa mensagens antigas (mais de 24 horas)
    $oneDayAgo = time() - 86400;
    foreach ($processedMessages as $id => $info) {
        if ($info['timestamp'] < $oneDayAgo) {
            unset($processedMessages[$id]);
        }
    }

    // Salva o cache
    file_put_contents($cacheFile, json_encode($processedMessages));
}

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

    // Verifica se a mensagem já foi processada
    $messageId = $data['messageId'] ?? '';
    if (!empty($messageId) && isMessageProcessed($messageId)) {
        Logger::log('info', 'Ignoring already processed message', [
            'messageId' => $messageId,
            'message' => $data['text']['message'] ?? $data['message'] ?? ''
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
    if (!empty($messageId)) {
        $additionalData['messageId'] = $messageId;

        // Marca a mensagem como processada
        markMessageAsProcessed($messageId, [
            'phone' => $phone,
            'message' => $message,
            'fromMe' => $data['fromMe'] ?? false,
            'fromApi' => $data['fromApi'] ?? false,
            'timestamp' => time()
        ]);
    }

    // Se a mensagem foi enviada pelo usuário diretamente do WhatsApp (não pela API)
    if (isset($data['fromMe']) && $data['fromMe'] && !(isset($data['fromApi']) && $data['fromApi'])) {
        $messageType = 'outgoing'; // Mensagens enviadas pelo usuário devem aparecer como saída
        Logger::log('info', 'Message sent directly from WhatsApp (not through API)', [
            'fromMe' => $data['fromMe'] ?? false,
            'fromApi' => $data['fromApi'] ?? false,
            'message_type' => $messageType,
            'messageId' => $messageId
        ]);
    }

    $chatwoot->sendMessage($phone, $message, $attachments, $messageType, $additionalData);
}

function handleChatwootMessage($data)
{
    // Log completo do payload para debug
    Logger::log('debug', 'Chatwoot full payload', ['payload' => $data]);

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
        'additional_attributes_json' => isset($data['additional_attributes']) ? json_encode($data['additional_attributes']) : 'not set',
        'sender' => $data['sender'] ?? 'not set',
        'sender_type' => isset($data['sender']) ? ($data['sender']['type'] ?? 'not set') : 'not set',
        'sender_id' => isset($data['sender']) ? ($data['sender']['id'] ?? 'not set') : 'not set',
        'conversation' => $data['conversation'] ?? 'not set',
        'conversation_messages' => isset($data['conversation']) && isset($data['conversation']['messages']) ?
            count($data['conversation']['messages']) . ' messages' : 'not set',
        'first_message' => isset($data['conversation']) && isset($data['conversation']['messages']) &&
            !empty($data['conversation']['messages']) ? json_encode($data['conversation']['messages'][0]) : 'not set'
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
    $messageId = null;
    if (isset($data['additional_attributes']) &&
        is_array($data['additional_attributes']) &&
        isset($data['additional_attributes']['messageId'])) {

        $messageId = $data['additional_attributes']['messageId'];
        Logger::log('info', 'Ignoring message with WhatsApp messageId', [
            'message' => $data['content'] ?? '',
            'messageId' => $messageId
        ]);
        return;
    }

    // Gera um ID único para a mensagem do Chatwoot
    $chatwootMessageId = 'chatwoot_' . ($data['id'] ?? uniqid());

    // Verifica se a mensagem já foi processada
    if (isMessageProcessed($chatwootMessageId)) {
        Logger::log('info', 'Ignoring already processed Chatwoot message', [
            'chatwootMessageId' => $chatwootMessageId,
            'message' => $data['content'] ?? ''
        ]);
        return;
    }

    // Verifica se a mensagem tem o atributo 'source_id' nulo ou vazio
    // Isso pode indicar que a mensagem foi criada pelo nosso sistema
    // Mas também pode ser uma mensagem enviada pelo agente no Chatwoot
    // Vamos verificar se o sender_type é 'user' para diferenciar
    if (empty($data['source_id']) &&
        isset($data['sender']) &&
        isset($data['sender']['type']) &&
        $data['sender']['type'] === 'user') {

        // Se o sender_type é 'user', é uma mensagem enviada pelo agente no Chatwoot
        // Neste caso, devemos processar a mensagem normalmente
        Logger::log('info', 'Processing message sent by agent in Chatwoot', [
            'message' => $data['content'] ?? '',
            'sender_type' => $data['sender']['type'] ?? 'not set',
            'chatwootMessageId' => $chatwootMessageId
        ]);
    } else if (empty($data['source_id'])) {
        // Se não tem source_id e não é do tipo 'user', provavelmente foi criada pelo nosso sistema
        Logger::log('info', 'Ignoring message with no source_id (likely created by our system)', [
            'message' => $data['content'] ?? '',
            'sender_type' => isset($data['sender']) ? ($data['sender']['type'] ?? 'not set') : 'not set'
        ]);
        return;
    }

    $zapi = new ZAPIHandler();
    $phone = $data['conversation']['contact_inbox']['source_id'] ?? '';
    $message = $data['content'] ?? '';

    if (empty($phone) || empty($message)) {
        Logger::log('error', 'Missing required data for sending message');
        return;
    }

    // Marca a mensagem como processada antes de enviá-la
    markMessageAsProcessed($chatwootMessageId, [
        'phone' => $phone,
        'message' => $message,
        'sender_type' => isset($data['sender']) ? ($data['sender']['type'] ?? 'not set') : 'not set',
        'timestamp' => time()
    ]);

    $zapi->sendMessage($phone, $message);
}
