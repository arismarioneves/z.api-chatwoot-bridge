<?php

namespace WhatsappBridge;

class ChatwootHandler
{
    private $apiToken;
    private $accountId;
    private $inboxId;
    private $baseUrl;

    public function __construct()
    {
        $this->apiToken = CHATWOOT_API_TOKEN;
        $this->accountId = CHATWOOT_ACCOUNT_ID;
        $this->inboxId = CHATWOOT_INBOX_ID;
        $this->baseUrl = CHATWOOT_BASE_URL;
    }

    public function sendMessage($sourceId, $message)
    {
        Logger::log('info', 'Preparing to send message to Chatwoot', [
            'source_id' => $sourceId,
            'message' => $message
        ]);

        $phone = $this->formatPhoneNumber($sourceId);

        // Busca contato existente
        $contactId = $this->findOrCreateContact($phone);
        if (!$contactId) {
            Logger::log('error', 'Failed to find or create contact');
            return false;
        }

        // Busca conversa existente
        $conversationId = $this->findOrCreateConversation($phone, $contactId);
        if (!$conversationId) {
            Logger::log('error', 'Failed to find or create conversation');
            return false;
        }

        // Envia a mensagem
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";

        $data = [
            'content' => $message,
            'message_type' => 'incoming',
            'private' => false
        ];

        Logger::log('info', 'Sending message via Chatwoot', [
            'source_id' => $sourceId,
            'endpoint' => $endpoint
        ]);

        return $this->makeRequest('POST', $endpoint, $data);
    }

    private function findOrCreateContact($phone)
    {
        // Primeiro, tenta buscar pelo source_id
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search";
        $searchResponse = $this->makeRequest('GET', $searchEndpoint . '?q=' . urlencode($phone));

        if (!empty($searchResponse['payload']) && count($searchResponse['payload']) > 0) {
            $contact = $searchResponse['payload'][0];
            // Atualiza o perfil do contato existente
            $this->updateContactProfile($contact['id'], $phone);
            return $contact['id'];
        }

        // Se não encontrou, cria novo contato
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts";

        // Busca informações do perfil no WhatsApp
        $profileInfo = $this->getWhatsAppProfile($phone);

        $data = [
            'inbox_id' => (int)$this->inboxId,
            'name' => $profileInfo['name'] ?? $phone,
            'phone_number' => $phone,
            'identifier' => $phone,
            'source_id' => $phone
        ];

        if (!empty($profileInfo['avatar_url'])) {
            $data['avatar_url'] = $profileInfo['avatar_url'];
        }

        $response = $this->makeRequest('POST', $endpoint, $data);

        return $response['id'] ?? null;
    }

    private function findOrCreateConversation($phone, $contactId)
    {
        // Busca conversa existente
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
        $searchParams = http_build_query([
            'inbox_id' => $this->inboxId,
            'contact_id' => $contactId,
            'status' => 'all'
        ]);

        $response = $this->makeRequest('GET', $searchEndpoint . '?' . $searchParams);

        if (!empty($response['data'])) {
            // Prioriza conversas abertas
            foreach ($response['data'] as $conversation) {
                if ($conversation['status'] === 'open') {
                    return $conversation['id'];
                }
            }
            // Se não houver abertas, pega a última conversa
            return $response['data'][0]['id'];
        }

        // Se não encontrou, cria nova conversa
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";

        $data = [
            'source_id' => $phone,
            'inbox_id' => (int)$this->inboxId,
            'contact_id' => (int)$contactId,
            'status' => 'open',
            'additional_attributes' => [
                'whatsapp_phone' => $phone
            ]
        ];

        $response = $this->makeRequest('POST', $endpoint, $data);

        return $response['id'] ?? null;
    }

    private function getWhatsAppProfile($phone)
    {
        try {
            $zapi = new ZAPIHandler();
            return $zapi->getProfileInfo($phone);
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to get WhatsApp profile', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function updateContactProfile($contactId, $phone)
    {
        $profileInfo = $this->getWhatsAppProfile($phone);
        if (empty($profileInfo)) return;

        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}";
        $data = [
            'name' => $profileInfo['name'] ?? $phone
        ];

        if (!empty($profileInfo['avatar_url'])) {
            $data['avatar_url'] = $profileInfo['avatar_url'];
        }

        $this->makeRequest('PUT', $endpoint, $data);
    }

    private function makeRequest($method, $url, $data = null)
    {
        $headers = [
            'Content-Type: application/json',
            'api_access_token: ' . $this->apiToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        Logger::log('debug', 'Chatwoot Request', [
            'url' => $url,
            'method' => $method,
            'response' => $response,
            'http_code' => $httpCode
        ]);

        curl_close($ch);

        return json_decode($response, true);
    }

    private function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Mantém formato E.164: 55DDDNNNNNNNN
        if (strlen($phone) === 12 && str_starts_with($phone, '55')) {
            return $phone;
        }
        return '55' . ltrim($phone, '55'); // Remove duplicações
    }
}
