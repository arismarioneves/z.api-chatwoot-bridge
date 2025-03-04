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

        // Processa apenas mensagens recebidas
        if ($payload['type'] !== 'ReceivedCallback') {
            Logger::log('info', 'Ignoring non-received Z-API webhook', ['type' => $payload['type']]);
            return false;
        }

        Logger::log('info', 'Processing Z-API webhook', ['data' => $payload]);

        try {
            // Extrai os dados da mensagem
            $phone = $payload['phone'];
            $message = $payload['text']['message'];

            // Verifica se há anexos
            $hasAttachments = $this->checkForAttachments($payload);

            Logger::log('info', 'Sending to Chatwoot', [
                'phone' => $phone,
                'message' => $message,
                'has_attachments' => $hasAttachments
            ]);

            // Processa anexos se houver
            $attachments = [];
            if ($hasAttachments) {
                $attachments = $this->processAttachments($payload);
            }

            // Envia para o Chatwoot
            $response = $this->chatwoot->sendMessage($phone, $message, $attachments);

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

        // Processa apenas mensagens de saída que não são privadas
        if (!$this->isValidChatwootMessage($payload)) {
            return false;
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
