<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class ChatwootHandler
{
    private string $apiToken;
    private string $accountId;
    private string $inboxId;
    private string $baseUrl;
    private ZAPIHandler $zapi;

    public function __construct()
    {
        if (!defined('CHATWOOT_API_TOKEN') || !defined('CHATWOOT_ACCOUNT_ID') || !defined('CHATWOOT_INBOX_ID') || !defined('CHATWOOT_BASE_URL')) {
            throw new \Exception("Chatwoot configuration constants are not defined.");
        }
        $this->apiToken = CHATWOOT_API_TOKEN;
        $this->accountId = CHATWOOT_ACCOUNT_ID;
        $this->inboxId = CHATWOOT_INBOX_ID;
        $this->baseUrl = rtrim(CHATWOOT_BASE_URL, '/');
        $this->zapi = new ZAPIHandler();
    }

    public function sendMessage(string $sourceId, string $message, array $attachments = [], string $messageType = 'incoming'): ?array
    {
        $phone = Formatter::formatPhoneNumber($sourceId);

        try {
            $contactId = $this->findOrCreateContact($phone);
            if (!$contactId)
                return null;

            $conversationId = $this->findOrCreateConversation($phone, $contactId);
            if (!$conversationId)
                return null;

            $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";
            $data = [
                'content' => $message,
                'message_type' => $messageType,
                'private' => false,
            ];

            return $this->makeRequest('POST', $endpoint, $data);
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to send to Chatwoot', ['error' => $e->getMessage(), 'phone' => $phone]);
            return null;
        }
    }

    private function findOrCreateContact(string $phone): ?int
    {
        $e164Phone = '+' . $phone;
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search";
        $searchResponse = $this->makeRequest('GET', $searchEndpoint, ['q' => $phone]);

        if (!empty($searchResponse['payload'])) {
            foreach ($searchResponse['payload'] as $contact) {
                $contactPhone = $contact['phone_number'] ?? null;
                $contactIdentifier = $contact['identifier'] ?? null;
                $formattedContactPhone = $contactPhone ? Formatter::formatPhoneNumber($contactPhone) : null;

                $phoneMatches = $formattedContactPhone === $phone;
                $phoneContains = $contactPhone && (str_contains($contactPhone, $phone) || str_contains($phone, preg_replace('/\D/', '', $contactPhone)));
                $identifierMatches = $contactIdentifier === $phone;

                if ($phoneMatches || $phoneContains || $identifierMatches) {
                    Logger::log('info', 'Found contact', ['id' => $contact['id']]);
                    return $contact['id'];
                }
            }
        }

        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts";
        $profileInfo = $this->zapi->getProfileInfo($phone);

        $data = [
            'inbox_id' => (int) $this->inboxId,
            'name' => $profileInfo['name'] ?? $phone,
            'phone_number' => $e164Phone,
            'identifier' => $phone,
            'custom_attributes' => ['whatsapp_phone' => $phone]
        ];

        if (!empty($profileInfo['avatar_url'])) {
            $data['avatar_url'] = $profileInfo['avatar_url'];
        }

        $createResponse = $this->makeRequest('POST', $createEndpoint, $data);
        $contactId = $createResponse['payload']['contact']['id'] ?? $createResponse['id'] ?? null;

        if ($contactId) {
            Logger::log('info', 'Created contact', ['id' => $contactId]);
            return $contactId;
        }

        $errorMessage = $createResponse['message'] ?? '';
        if (str_contains($errorMessage, 'already been taken')) {
            sleep(1);
            $searchResponse = $this->makeRequest('GET', $searchEndpoint, ['q' => $phone]);
            if (!empty($searchResponse['payload'])) {
                foreach ($searchResponse['payload'] as $contact) {
                    $contactPhone = $contact['phone_number'] ?? null;
                    $formattedContactPhone = $contactPhone ? Formatter::formatPhoneNumber($contactPhone) : null;
                    if ($formattedContactPhone === $phone || ($contact['identifier'] ?? null) === $phone) {
                        return $contact['id'];
                    }
                }
            }
        }

        Logger::log('error', 'Failed to find/create contact', ['phone' => $phone]);
        return null;
    }

    private function findOrCreateConversation(string $phone, int $contactId): ?int
    {
        $foundConversationId = null;
        $foundConversationStatus = null;

        $listEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}/conversations";
        $listResponse = $this->makeRequest('GET', $listEndpoint);

        if (!empty($listResponse['payload']) && is_array($listResponse['payload'])) {
            foreach ($listResponse['payload'] as $conversation) {
                $conversationInboxId = $conversation['inbox_id'] ?? null;
                $conversationStatus = $conversation['status'] ?? 'unknown';

                if ($conversationInboxId == $this->inboxId) {
                    if ($conversationStatus === 'open') {
                        Logger::log('info', 'Found open conversation', ['id' => $conversation['id']]);
                        return $conversation['id'];
                    } elseif (!$foundConversationId) {
                        $foundConversationId = $conversation['id'];
                        $foundConversationStatus = $conversationStatus;
                    }
                }
            }
        }

        if ($foundConversationId) {
            Logger::log('info', 'Reopening conversation', ['id' => $foundConversationId]);
            $updateEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$foundConversationId}/toggle_status";
            $this->makeRequest('POST', $updateEndpoint, ['status' => 'open']);
            return $foundConversationId;
        }

        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
        $data = [
            'inbox_id' => (int) $this->inboxId,
            'contact_id' => $contactId,
            'source_id' => $phone,
            'status' => 'open'
        ];

        $createResponse = $this->makeRequest('POST', $createEndpoint, $data);
        $conversationId = $createResponse['id'] ?? $createResponse['payload']['id'] ?? null;

        if ($conversationId) {
            Logger::log('info', 'Created conversation', ['id' => $conversationId]);
            return $conversationId;
        }

        Logger::log('error', 'Failed to create conversation', ['contact_id' => $contactId]);
        return null;
    }

    private function makeRequest(string $method, string $url, ?array $data = null): ?array
    {
        $headers = ['api_access_token: ' . $this->apiToken];
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Logger::log('error', 'Chatwoot cURL error', ['error' => $curlError]);
            throw new \Exception("Chatwoot cURL request failed: " . $curlError);
        }

        $decodedResponse = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::log('error', 'Chatwoot HTTP error', ['code' => $httpCode, 'response' => $decodedResponse]);
        }

        return $decodedResponse;
    }

    public function updateContact(string $phone, array $attributes): bool
    {
        $contactId = $this->findOrCreateContact($phone);
        if (!$contactId) {
            return false;
        }

        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}";
        $this->makeRequest('PUT', $endpoint, $attributes);

        Logger::log('info', 'Updated contact', ['phone' => $phone, 'attributes' => $attributes]);
        return true;
    }
}
