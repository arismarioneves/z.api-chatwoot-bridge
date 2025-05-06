<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class WebhookHandler
{
    private ChatwootHandler $chatwoot;
    private ZAPIHandler $zapi;
    // Caractere invisível para evitar loops (Zero-Width Space)
    private const INVISIBLE_MARKER = "\xE2\x80\x8B";

    public function __construct()
    {
        $this->chatwoot = new ChatwootHandler();
        $this->zapi = new ZAPIHandler();
    }

    /**
     * Handle incoming webhooks from both Z-API and Chatwoot
     *
     * @param array $payload The webhook payload
     * @return bool True if webhook was processed successfully, False if ignored.
     * @throws \InvalidArgumentException If webhook format is not recognized or data is missing.
     * @throws \Exception For other processing errors.
     */
    public function handle(array $payload): bool
    {
        Logger::log('info', 'Handling webhook', ['payload_keys' => array_keys($payload)]);

        // Verifica se é um webhook do Z-API
        if ($this->isZAPIWebhook($payload)) {
            return $this->handleZAPIWebhook($payload);
        }

        // Verifica se é um webhook do Chatwoot
        if ($this->isChatwootWebhook($payload)) {
            return $this->handleChatwootWebhook($payload);
        }

        Logger::log('warning', 'Unrecognized webhook format', ['payload_keys' => array_keys($payload)]);
        // Lança exceção específica para formato inválido
        throw new \InvalidArgumentException('Unrecognized webhook format');
    }

    /** Check if the webhook is from Z-API */
    private function isZAPIWebhook(array $payload): bool
    {
        // Critérios básicos para identificar um webhook da Z-API
        return isset($payload['messageId'], $payload['type']) && (isset($payload['phone']) || isset($payload['chatId']));
    }

    /** Check if the webhook is from Chatwoot */
    private function isChatwootWebhook(array $payload): bool
    {
        // Critérios para identificar um webhook do Chatwoot
        return isset($payload['event'], $payload['conversation'], $payload['message_type']);
    }

    /** Handle webhooks from Z-API (WhatsApp messages) */
    private function handleZAPIWebhook(array $payload): bool
    {
        Logger::log('info', 'Z-API webhook identified', ['type' => $payload['type'] ?? 'N/A']);

        // Extrai dados essenciais
        $phone = Formatter::formatPhoneNumber($payload['phone'] ?? $payload['chatId'] ?? null); // 'chatId' pode conter o número
        $messageText = $payload['text']['message'] ?? $payload['message'] ?? ''; // Z-API pode usar 'message' ou 'text.message'
        $attachments = $this->processZAPIAttachments($payload);

        if (empty($phone)) {
            Logger::log('warning', 'Ignoring Z-API message: Phone number missing.', ['payload_keys' => array_keys($payload)]);
            return false; // Ignorado por falta de dados
        }

        // Se não houver texto nem anexo, ignora (ex: status visto, digitação)
        if (empty($messageText) && empty($attachments)) {
            Logger::log('info', 'Ignoring Z-API message: No text or attachments found.', ['type' => $payload['type'] ?? 'N/A']);
            return false; // Ignorado
        }

        // Se só houver anexo, define uma mensagem padrão
        if (empty($messageText) && !empty($attachments)) {
            $messageText = '[Mídia]'; // Mensagem placeholder para mídia sem legenda
        }

        // Determina o tipo de mensagem (incoming/outgoing)
        $messageType = 'incoming'; // Padrão: mensagem recebida
        if (isset($payload['fromMe']) && $payload['fromMe']) {
            // Mensagem enviada pelo usuário diretamente no WhatsApp (não via API, já filtrado acima)
            $messageType = 'outgoing';
            Logger::log('info', 'Z-API message marked as outgoing (sent directly from WhatsApp)', ['phone' => $phone]);
        }

        // Verifica se a mensagem veio originalmente do Chatwoot ou da API (contém o marcador invisível)
        if (str_ends_with($messageText, self::INVISIBLE_MARKER)) {
            Logger::log('info', 'Ignoring Z-API message: Detected invisible marker (origin Chatwoot outgoing).');
            return false; // Evita duplicação de mensagens
        }

        Logger::log('info', 'Processing Z-API message to send to Chatwoot', [
            'phone' => $phone,
            'message_type' => $messageType,
            'has_attachments' => !empty($attachments)
        ]);

        // Envia para o Chatwoot
        try {
            $this->chatwoot->sendMessage($phone, $messageText, $attachments, $messageType);
            return true; // Processado com sucesso
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to send Z-API message to Chatwoot', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'zapi_payload_keys' => array_keys($payload)
            ]);
            // Re-lança a exceção para ser capturada no ponto de entrada
            throw new \Exception("Failed to forward Z-API message to Chatwoot: " . $e->getMessage(), 0, $e);
        }
    }

    /** Handle webhooks from Chatwoot (agent responses) */
    private function handleChatwootWebhook(array $payload): bool
    {
        $event = $payload['event'];
        Logger::log('info', 'Chatwoot webhook identified', ['event' => $event]);

        // Processa apenas mensagens criadas que devem ser enviadas
        if ($event !== 'message_created') {
            Logger::log('info', 'Ignoring Chatwoot event: Not message_created.', ['event' => $event]);
            return false; // Ignora outros eventos
        }

        if (!$this->isValidChatwootMessageToSend($payload)) {
            Logger::log('info', 'Ignoring Chatwoot message: Invalid type, private, or no content/attachments.');
            return false; // Ignora mensagens inválidas
        }

        // Extrai dados
        $sourceId = $payload['conversation']['contact_inbox']['source_id'] ?? null;
        $chatwootAttachments = $payload['attachments'] ?? [];

        if (empty($sourceId)) {
            Logger::log('warning', 'Ignoring Chatwoot message: Source ID (phone) missing in conversation.', ['payload_keys' => array_keys($payload)]);
            return false; // Ignorado por falta de dados
        }

        $phone = Formatter::formatPhoneNumber($sourceId);

        Logger::log('info', 'Processing Chatwoot message to send to Z-API', [
            'phone' => $phone,
            'has_text' => !empty($messageToSend),
            'attachment_count' => count($chatwootAttachments)
        ]);

        try {
            // Envia anexos PRIMEIRO, se houver
            if (!empty($chatwootAttachments)) {
                foreach ($chatwootAttachments as $attachment) {
                    if (isset($attachment['data_url'])) {
                        // Envia cada anexo individualmente (Z-API geralmente não suporta múltiplos anexos em uma chamada)
                        // Assume que a primeira mídia pode ter a legenda/texto. As demais vão sem.
                        $caption = (empty($messageToSend) ? '' : $messageToSend); // Usa o texto como legenda para o primeiro anexo se houver
                        $this->zapi->sendMediaMessage($phone, $attachment['data_url'], $caption, $attachment['file_type'] ?? 'file', $attachment['name'] ?? null);
                        $messageToSend = ''; // Limpa a mensagem principal após usar como legenda
                        usleep(500000); // Pequena pausa entre envios de mídia (0.5 seg), se necessário
                    } else {
                        Logger::log('warning', 'Chatwoot attachment missing data_url', ['attachment' => $attachment]);
                    }
                }
            }

            // Envia a mensagem de texto SE AINDA HOUVER (não foi usada como legenda)
            if (!empty($messageToSend)) {
                $this->zapi->sendMessage($phone, $messageToSend);
            }

            return true; // Processado

        } catch (\Exception $e) {
            Logger::log('error', 'Failed to send Chatwoot message to Z-API', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'chatwoot_payload_keys' => array_keys($payload)
            ]);
            throw new \Exception("Failed to forward Chatwoot message to Z-API: " . $e->getMessage(), 0, $e);
        }
    }

    /** Check if the message from Chatwoot is valid for sending to Z-API */
    private function isValidChatwootMessageToSend(array $payload): bool
    {
        $isOutgoing = ($payload['message_type'] ?? '') === 'outgoing';
        $isPrivate = ($payload['private'] ?? false) === true;
        $hasContent = isset($payload['content']) && $payload['content'] !== null && $payload['content'] !== ''; // Permite '0' como conteúdo
        $hasAttachments = !empty($payload['attachments']);

        // Deve ser 'outgoing', não 'private', e ter conteúdo ou anexos
        return $isOutgoing && !$isPrivate && ($hasContent || $hasAttachments);
    }

    /** Process attachments from Z-API webhook */
    private function processZAPIAttachments(array $payload): array
    {
        $attachments = [];
        $mediaUrl = null;
        $mimeType = $payload['mimetype'] ?? null;
        $fileName = $payload['fileName'] ?? null; // Pode vir em 'document' ou similar

        // Tenta obter a URL de mídia principal (pode variar na Z-API)
        if (isset($payload['mediaUrl'])) {
            $mediaUrl = $payload['mediaUrl'];
        } elseif (isset($payload['media'])) { // Estrutura alternativa
            $mediaUrl = $payload['media'];
        } elseif (isset($payload['image']['imageUrl'])) {
            $mediaUrl = $payload['image']['imageUrl'];
            $mimeType = $mimeType ?? 'image/jpeg'; // Assume se não vier
        } elseif (isset($payload['video']['videoUrl'])) {
            $mediaUrl = $payload['video']['videoUrl'];
            $mimeType = $mimeType ?? 'video/mp4';
        } elseif (isset($payload['audio']['audioUrl'])) {
            $mediaUrl = $payload['audio']['audioUrl'];
            $mimeType = $mimeType ?? 'audio/ogg';
        } elseif (isset($payload['document']['documentUrl'])) {
            $mediaUrl = $payload['document']['documentUrl'];
            $mimeType = $mimeType ?? 'application/octet-stream';
            $fileName = $fileName ?? $payload['document']['fileName'] ?? 'document';
        }

        if ($mediaUrl && $mimeType) {
            // Determina o tipo do Chatwoot baseado no MimeType
            $fileType = 'file'; // Padrão
            if (str_starts_with($mimeType, 'image/')) {
                $fileType = 'image';
            } elseif (str_starts_with($mimeType, 'audio/')) {
                $fileType = 'audio';
            } elseif (str_starts_with($mimeType, 'video/')) {
                $fileType = 'video';
            }

            $attachmentData = [
                'url' => $mediaUrl,
                'type' => $fileType // Usa o tipo do Chatwoot (image, audio, video, file)
            ];
            $attachments[] = $attachmentData;

            Logger::log('debug', 'Processed Z-API attachment', $attachmentData);
        } elseif (isset($payload['type']) && in_array(strtolower($payload['type']), ['image', 'video', 'audio', 'document', 'sticker']) && !isset($payload['mediaUrl'])) {
            // Loga aviso se o tipo indica mídia mas não encontramos a URL
            Logger::log('warning', 'Z-API message type indicates media, but media URL not found in expected fields.', [
                'type' => $payload['type'],
                'payload_keys' => array_keys($payload)
            ]);
        }

        return $attachments;
    }
}
