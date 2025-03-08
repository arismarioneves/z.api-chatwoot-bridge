<?php

namespace WhatsappBridge;

class WebhookHandler
{
    private $chatwoot;
    private $zapi;

    public function __construct()
    {
        $this->chatwoot = new ChatwootHandler();
        $this->zapi = new ZAPIHandler();
    }

    /**
     * Handle incoming webhooks from both Z-API and Chatwoot
     *
     * @param array $payload The webhook payload
     * @return bool True if webhook was processed successfully
     * @throws \Exception If webhook format is not recognized
     */
    public function handle($payload)
    {
        Logger::log('info', 'Raw webhook received', ['payload' => $payload]);

        try {
            // Verifica se é um webhook do Z-API
            if ($this->isZAPIWebhook($payload)) {
                return $this->handleZAPIWebhook($payload);
            }

            // Verifica se é um webhook do Chatwoot
            if ($this->isChatwootWebhook($payload)) {
                return $this->handleChatwootWebhook($payload);
            }

            Logger::log('warning', 'Unrecognized webhook format', ['data' => $payload]);
            throw new \Exception('Unrecognized webhook format');
        } catch (\Exception $e) {
            Logger::log('error', 'Webhook processing failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if the webhook is from Z-API
     */
    private function isZAPIWebhook($payload): bool
    {
        return isset($payload['phone']) &&
            isset($payload['text']) &&
            isset($payload['type']);
    }

    /**
     * Check if the webhook is from Chatwoot
     */
    private function isChatwootWebhook($payload): bool
    {
        return isset($payload['event']) &&
            isset($payload['message_type']) &&
            isset($payload['conversation']);
    }

    /**
     * Handle webhooks from Z-API (WhatsApp messages)
     */
    private function handleZAPIWebhook($payload): bool
    {
        Logger::log('info', 'Z-API webhook received', ['data' => $payload]);

        // Verifica se é uma mensagem enviada pelo sistema através da API
        if (isset($payload['fromMe']) && $payload['fromMe'] && isset($payload['fromApi']) && $payload['fromApi']) {
            Logger::log('info', 'Ignoring message sent by the system through API', [
                'fromMe' => $payload['fromMe'] ?? false,
                'fromApi' => $payload['fromApi'] ?? false
            ]);
            return false;
        }

        // Processa apenas mensagens recebidas ou enviadas diretamente pelo WhatsApp
        if ($payload['type'] !== 'ReceivedCallback' && !(isset($payload['fromMe']) && $payload['fromMe'] && !(isset($payload['fromApi']) && $payload['fromApi']))) {
            Logger::log('info', 'Ignoring non-received Z-API webhook', [
                'type' => $payload['type'],
                'fromMe' => $payload['fromMe'] ?? false,
                'fromApi' => $payload['fromApi'] ?? false
            ]);
            return false;
        }

        Logger::log('info', 'Processing Z-API webhook', ['data' => $payload]);

        try {
            // Extrai os dados da mensagem
            $phone = $payload['phone'];
            $message = $payload['text']['message'];

            // Verifica se há anexos
            $hasAttachments = $this->checkForAttachments($payload);

            // Determina o tipo de mensagem com base nos flags fromMe e fromApi
            $messageType = 'incoming'; // Padrão para mensagens recebidas de terceiros

            // Prepara dados adicionais para a mensagem
            $additionalData = [];

            // Adiciona o messageId se disponível
            if (isset($payload['messageId'])) {
                $additionalData['messageId'] = $payload['messageId'];
            }

            // Se a mensagem foi enviada pelo usuário diretamente do WhatsApp (não pela API)
            if (isset($payload['fromMe']) && $payload['fromMe'] && !(isset($payload['fromApi']) && $payload['fromApi'])) {
                $messageType = 'outgoing'; // Mensagens enviadas pelo usuário devem aparecer como saída
                Logger::log('info', 'Message sent directly from WhatsApp (not through API)', [
                    'fromMe' => $payload['fromMe'] ?? false,
                    'fromApi' => $payload['fromApi'] ?? false,
                    'message_type' => $messageType,
                    'messageId' => $payload['messageId'] ?? 'not set'
                ]);
            }

            Logger::log('info', 'Sending to Chatwoot', [
                'phone' => $phone,
                'message' => $message,
                'has_attachments' => $hasAttachments,
                'message_type' => $messageType,
                'additional_data' => $additionalData
            ]);

            // Processa anexos se houver
            $attachments = [];
            if ($hasAttachments) {
                $attachments = $this->processAttachments($payload);
            }

            // Envia para o Chatwoot com o tipo de mensagem apropriado
            $response = $this->chatwoot->sendMessage($phone, $message, $attachments, $messageType, $additionalData);

            return true;
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to process Z-API webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Handle webhooks from Chatwoot (agent responses)
     */
    private function handleChatwootWebhook($payload): bool
    {
        Logger::log('info', 'Chatwoot webhook received', ['data' => $payload]);

        // Log detalhado para debug
        Logger::log('debug', 'Chatwoot webhook details', [
            'message_type' => $payload['message_type'] ?? 'not set',
            'private' => $payload['private'] ?? 'not set',
            'content' => $payload['content'] ?? 'not set',
            'content_attributes' => $payload['content_attributes'] ?? 'not set',
            'source_id' => $payload['source_id'] ?? 'not set',
            'has_content_attributes' => isset($payload['content_attributes']),
            'content_attributes_type' => isset($payload['content_attributes']) ? gettype($payload['content_attributes']) : 'not set',
            'content_attributes_json' => isset($payload['content_attributes']) ? json_encode($payload['content_attributes']) : 'not set'
        ]);

        // Processa apenas mensagens de saída que não são privadas
        if (!$this->isValidChatwootMessage($payload)) {
            return false;
        }

        // Verifica se a mensagem tem o atributo 'source' com valor 'whatsapp_direct'
        // Isso indica que a mensagem foi enviada diretamente pelo WhatsApp e não deve ser reenviada
        if (isset($payload['content_attributes']) &&
            is_array($payload['content_attributes']) &&
            isset($payload['content_attributes']['source']) &&
            $payload['content_attributes']['source'] === 'whatsapp_direct') {

            Logger::log('info', 'Ignoring message sent directly from WhatsApp', [
                'message' => $payload['content'] ?? '',
                'source' => $payload['content_attributes']['source']
            ]);
            return true;
        }

        // Verifica se a mensagem tem o messageId do WhatsApp
        // Se tiver, significa que é uma mensagem que veio do WhatsApp e não deve ser reenviada
        if (isset($payload['additional_attributes']) &&
            is_array($payload['additional_attributes']) &&
            isset($payload['additional_attributes']['messageId'])) {

            Logger::log('info', 'Ignoring message with WhatsApp messageId', [
                'message' => $payload['content'] ?? '',
                'messageId' => $payload['additional_attributes']['messageId']
            ]);
            return true;
        }

        try {
            $conversation = $payload['conversation'];
            $sourceId = $conversation['contact_inbox']['source_id'];
            $message = $payload['content'];

            // Remove o prefixo '+' do número de telefone
            $phone = $this->formatPhoneNumber($sourceId);

            Logger::log('info', 'Sending to Z-API', [
                'phone' => $phone,
                'message' => $message
            ]);

            // Envia a mensagem via Z-API
            $response = $this->zapi->sendMessage($phone, $message);

            Logger::log('info', 'Z-API response', ['response' => $response]);
            return true;
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to process Chatwoot webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Check if the message from Chatwoot is valid for processing
     */
    private function isValidChatwootMessage($payload): bool
    {
        return $payload['message_type'] === 'outgoing' &&
            !$payload['private'] &&
            isset($payload['content']) &&
            !empty($payload['content']);
    }

    /**
     * Format phone number to E.164 format (55DDDNNNNNNNN)
     */
    private function formatPhoneNumber($phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Mantém formato E.164: 55DDDNNNNNNNN
        if (strlen($phone) === 12 && str_starts_with($phone, '55')) {
            return $phone;
        }
        return '55' . ltrim($phone, '55'); // Remove duplicações
    }

    /**
     * Check if the message has any attachments
     */
    private function checkForAttachments($payload): bool
    {
        return isset($payload['image']) ||
            isset($payload['video']) ||
            isset($payload['audio']) ||
            isset($payload['document']);
    }

    /**
     * Process attachments from Z-API webhook
     */
    private function processAttachments($payload): array
    {
        $attachments = [];

        // Processa imagem
        if (isset($payload['image'])) {
            $attachments[] = [
                'type' => 'image',
                'url' => $payload['image']['imageUrl']
            ];
        }

        // Processa vídeo
        if (isset($payload['video'])) {
            $attachments[] = [
                'type' => 'video',
                'url' => $payload['video']['videoUrl']
            ];
        }

        // Processa áudio
        if (isset($payload['audio'])) {
            $attachments[] = [
                'type' => 'audio',
                'url' => $payload['audio']['audioUrl']
            ];
        }

        // Processa documento
        if (isset($payload['document'])) {
            $attachments[] = [
                'type' => 'document',
                'url' => $payload['document']['documentUrl'],
                'filename' => $payload['document']['fileName'] ?? 'document'
            ];
        }

        return $attachments;
    }
}
