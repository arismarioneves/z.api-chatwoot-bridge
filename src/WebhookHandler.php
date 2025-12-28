<?php

namespace ZapiWoot;

use ZapiWoot\Utils\Formatter;
use ZapiWoot\Services\LidService;

class WebhookHandler
{
    private ChatwootHandler $chatwoot;
    private ZAPIHandler $zapi;
    private LidService $lidService;
    private const INVISIBLE_MARKER = "\xE2\x80\x8B";

    public function __construct(?\PDO $pdo = null)
    {
        $this->chatwoot = new ChatwootHandler();
        $this->zapi = new ZAPIHandler();
        $this->lidService = new LidService($pdo);
    }

    public function handle(array $payload): bool
    {
        if ($this->isZAPIWebhook($payload)) {
            return $this->handleZAPIWebhook($payload);
        }

        if ($this->isChatwootWebhook($payload)) {
            return $this->handleChatwootWebhook($payload);
        }

        Logger::log('warning', 'Unrecognized webhook format');
        throw new \InvalidArgumentException('Unrecognized webhook format');
    }

    private function isZAPIWebhook(array $payload): bool
    {
        return isset($payload['messageId'], $payload['type']) && (isset($payload['phone']) || isset($payload['chatId']));
    }

    private function isChatwootWebhook(array $payload): bool
    {
        return isset($payload['event'], $payload['conversation'], $payload['message_type']);
    }

    private function handleZAPIWebhook(array $payload): bool
    {
        $fromMe = $payload['fromMe'] ?? false;
        $fromApi = $payload['fromApi'] ?? false;
        $phoneRaw = $payload['phone'] ?? '';

        // Extrair LID e telefone do payload
        $lid = $this->lidService->extractLidFromPayload($payload);
        $phone = $this->lidService->extractPhoneFromPayload($payload);

        // Se não conseguiu extrair telefone diretamente, tentar resolver via LID
        if (!$phone && $lid) {
            $phone = $this->lidService->resolvePhone($lid);
        }

        // Fallback: tentar formatar phoneRaw diretamente
        if (!$phone && !Formatter::isLid($phoneRaw)) {
            $phone = Formatter::formatPhoneNumber($phoneRaw);
        }

        Logger::log('info', 'Z-API webhook', [
            'type' => $payload['type'] ?? 'N/A',
            'phoneRaw' => $phoneRaw,
            'phone' => $phone,
            'lid' => $lid,
            'fromMe' => $fromMe,
            'fromApi' => $fromApi
        ]);

        // Registrar mapeamento LID / Phone se temos ambos
        if ($phone && $lid) {
            $this->lidService->registerMapping($phone, $lid);
        }

        // Atualizar foto do perfil e registrar contato
        $photoUrl = $payload['photo'] ?? null;
        $senderName = $payload['senderName'] ?? null;

        if (!$fromMe && $phone && $photoUrl && $senderName) {
            $imageContent = @file_get_contents($photoUrl);
            if ($imageContent) {
                $dir = ROOT . 'arquivos/perfil/';
                $filename = $phone . '.png';
                file_put_contents($dir . $filename, $imageContent);

                $localUrl = HOST . 'arquivos/perfil/' . $filename;
                $this->chatwoot->updateContact($phone, [
                    'name' => $senderName,
                    'avatar_url' => $localUrl
                ]);

                // Registrar contato completo no banco
                $this->lidService->registerContact($phone, $lid, $senderName, $localUrl);
            }
        }

        // Ignorar mensagens já enviadas pela API
        if ($fromMe && $fromApi) {
            return false;
        }

        // Se não conseguimos resolver o telefone, logar e ignorar
        if (empty($phone)) {
            if ($lid) {
                Logger::log('warning', 'Could not resolve phone from LID', [
                    'lid' => $lid,
                    'phoneRaw' => $phoneRaw
                ]);
            }
            return false;
        }

        $messageText = $payload['text']['message'] ?? $payload['message'] ?? '';
        $attachments = $this->processZAPIAttachments($payload);

        if (empty($messageText) && empty($attachments)) {
            return false;
        }

        if (empty($messageText) && !empty($attachments)) {
            $messageText = '[Mídia]';
        }

        if (str_ends_with($messageText, self::INVISIBLE_MARKER)) {
            return false;
        }

        $messageType = $fromMe ? 'outgoing' : 'incoming';

        // Se for mensagem outgoing (mobile), adicionar marcador para evitar loop
        // Quando o Chatwoot gerar webhook de volta, o conteúdo terá o marcador
        // e será ignorado no handleChatwootWebhook
        if ($messageType === 'outgoing') {
            $messageText .= self::INVISIBLE_MARKER;
        }

        Logger::log('info', 'Sending to Chatwoot', ['phone' => $phone, 'type' => $messageType]);
        $this->chatwoot->sendMessage($phone, $messageText, $attachments, $messageType);
        return true;
    }

    private function handleChatwootWebhook(array $payload): bool
    {
        if ($payload['event'] !== 'message_created') {
            return false;
        }

        if (!$this->isValidChatwootMessageToSend($payload)) {
            return false;
        }

        // Verificar se a mensagem já contém o marcador (evita loop de mensagens mobile)
        $content = $payload['content'] ?? '';
        if (str_ends_with($content, self::INVISIBLE_MARKER)) {
            Logger::log('info', 'Chatwoot: Ignoring message with marker (mobile sync)');
            return false;
        }

        $phone = $payload['conversation']['meta']['sender']['phone_number'] ?? null;

        if (empty($phone)) {
            Logger::log('warning', 'Chatwoot: phone_number missing');
            return false;
        }

        $phone = Formatter::formatPhoneNumber($phone);
        $messageToSend = $content . self::INVISIBLE_MARKER;
        $attachments = $payload['attachments'] ?? [];

        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['data_url'])) {
                    $caption = empty($messageToSend) ? '' : $messageToSend;
                    $this->zapi->sendMediaMessage($phone, $attachment['data_url'], $caption, $attachment['file_type'] ?? 'file', $attachment['name'] ?? null);
                    $messageToSend = '';
                    usleep(500000);
                }
            }
        }

        if (!empty($messageToSend)) {
            $this->zapi->sendMessage($phone, $messageToSend);
        }

        return true;
    }

    private function isValidChatwootMessageToSend(array $payload): bool
    {
        $isOutgoing = ($payload['message_type'] ?? '') === 'outgoing';
        $isPrivate = ($payload['private'] ?? false) === true;
        $hasContent = isset($payload['content']) && $payload['content'] !== '';
        $hasAttachments = !empty($payload['attachments']);

        // Verificar se a mensagem foi enviada por um agente (não via API)
        // Mensagens criadas via API não têm sender ou têm sender_type diferente de 'user'
        $sender = $payload['sender'] ?? null;
        $senderType = $sender['type'] ?? null;
        $isFromAgent = $senderType === 'user'; // 'user' no Chatwoot significa agente/atendente

        // Só enviar para Z-API se foi um agente que enviou (não via API)
        return $isOutgoing && !$isPrivate && ($hasContent || $hasAttachments) && $isFromAgent;
    }

    private function processZAPIAttachments(array $payload): array
    {
        $attachments = [];
        $mediaUrl = null;
        $mimeType = $payload['mimetype'] ?? null;

        if (isset($payload['mediaUrl'])) {
            $mediaUrl = $payload['mediaUrl'];
        } elseif (isset($payload['media'])) {
            $mediaUrl = $payload['media'];
        } elseif (isset($payload['image']['imageUrl'])) {
            $mediaUrl = $payload['image']['imageUrl'];
            $mimeType = $mimeType ?? 'image/jpeg';
        } elseif (isset($payload['video']['videoUrl'])) {
            $mediaUrl = $payload['video']['videoUrl'];
            $mimeType = $mimeType ?? 'video/mp4';
        } elseif (isset($payload['audio']['audioUrl'])) {
            $mediaUrl = $payload['audio']['audioUrl'];
            $mimeType = $mimeType ?? 'audio/ogg';
        } elseif (isset($payload['document']['documentUrl'])) {
            $mediaUrl = $payload['document']['documentUrl'];
            $mimeType = $mimeType ?? 'application/octet-stream';
        }

        if ($mediaUrl && $mimeType) {
            $fileType = 'file';
            if (str_starts_with($mimeType, 'image/')) {
                $fileType = 'image';
            } elseif (str_starts_with($mimeType, 'audio/')) {
                $fileType = 'audio';
            } elseif (str_starts_with($mimeType, 'video/')) {
                $fileType = 'video';
            }

            $attachments[] = ['url' => $mediaUrl, 'type' => $fileType];
        }

        return $attachments;
    }
}
