<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class WebhookHandler
{
    private ChatwootHandler $chatwoot;
    private ZAPIHandler $zapi;
    private const INVISIBLE_MARKER = "\xE2\x80\x8B";

    public function __construct()
    {
        $this->chatwoot = new ChatwootHandler();
        $this->zapi = new ZAPIHandler();
    }

    public function handle(array $payload): bool
    {
        Logger::log('info', 'Webhook received', ['keys' => array_keys($payload)]);

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
        $phone = Formatter::formatPhoneNumber($phoneRaw);

        Logger::log('info', 'Z-API webhook', [
            'type' => $payload['type'] ?? 'N/A',
            'phone' => $phone,
            'fromMe' => $fromMe,
            'fromApi' => $fromApi
        ]);

        if ($fromMe && $fromApi) {
            return false;
        }

        if ($fromMe && !$fromApi && str_contains($phoneRaw, '@lid')) {
            Logger::log('info', 'Ignoring mobile message with chatLid');
            return false;
        }

        if (empty($phone)) {
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

        $phone = $payload['conversation']['meta']['sender']['phone_number'] ?? null;

        if (empty($phone)) {
            Logger::log('warning', 'Chatwoot: phone_number missing');
            return false;
        }

        $phone = Formatter::formatPhoneNumber($phone);
        $messageToSend = ($payload['content'] ?? '') . self::INVISIBLE_MARKER;
        $attachments = $payload['attachments'] ?? [];

        Logger::log('info', 'Sending to Z-API', ['phone' => $phone]);

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

        return $isOutgoing && !$isPrivate && ($hasContent || $hasAttachments);
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
