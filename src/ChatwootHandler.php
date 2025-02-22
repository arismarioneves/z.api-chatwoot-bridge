<?php

namespace WhatsappBridge;

class ChatwootHandler
{
    private $baseUrl;
    private $apiToken;
    private $accountId;
    private $inboxId;

    public function __construct()
    {
        $this->baseUrl = CHATWOOT_BASE_URL;
        $this->apiToken = CHATWOOT_API_TOKEN;
        $this->accountId = CHATWOOT_ACCOUNT_ID;
        $this->inboxId = CHATWOOT_INBOX_ID;
    }

    public function handle($payload)
    {
        Logger::log('info', 'Webhook received', ['payload' => $payload]);

        // Verifica se é um webhook do Chatwoot
        if (isset($payload['event']) && $payload['event'] === 'message_updated') {
            // Ignora atualizações de status de mensagem
            return true;
        }

        // Verifica se é uma mensagem do agente
        if (isset($payload['message_type']) && $payload['message_type'] === 'outgoing') {
            $message = $payload['content'];
            $phone = null;

            // Tenta obter o número do telefone da conversa
            if (isset($payload['conversation']['contact_inbox']['source_id'])) {
                $phone = $payload['conversation']['contact_inbox']['source_id'];
                // Remove o + do início do número
                $phone = ltrim($phone, '+');
            }

            if ($phone && $message) {
                $zapi = new ZAPIHandler();
                return $zapi->sendMessage($phone, $message);
            }
        }

        Logger::log('warning', 'Unrecognized webhook format', ['data' => $payload]);
        throw new \Exception('Unrecognized webhook format');
    }

    public function sendMessage($sourceId, $message, $attachments = [])
    {
        Logger::log('info', 'Preparing to send message to Chatwoot', [
            'source_id' => $sourceId,
            'message' => $message
        ]);

        $formattedPhone = $this->formatPhoneNumber($sourceId);

        // Primeiro, vamos criar ou buscar o contato
        $contactId = $this->getOrCreateContact($formattedPhone, $sourceId);

        if (!$contactId) {
            Logger::log('error', 'Failed to create or get contact');
            return false;
        }

        // Depois, vamos buscar ou criar a conversa
        $conversationId = $this->getOrCreateConversation($contactId);

        if (!$conversationId) {
            Logger::log('error', 'Failed to create or get conversation');
            return false;
        }

        // Agora vamos enviar a mensagem
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";

        $data = [
            'content' => $message,
            'message_type' => 'incoming',
            'private' => false
        ];

        if (!empty($attachments)) {
            $data['attachments'] = $attachments;
        }

        $response = $this->makeRequest('POST', $endpoint, $data);

        Logger::log('info', 'Send message response', [
            'response' => $response
        ]);

        return $response;
    }

    private function getOrCreateContact($phone, $originalPhone)
    {
        // Busca o contato pelo número de telefone
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search?q=" . urlencode($phone);
        $searchResponse = $this->makeRequest('GET', $searchEndpoint);

        if (!empty($searchResponse['payload']) && is_array($searchResponse['payload'])) {
            foreach ($searchResponse['payload'] as $contact) {
                if (isset($contact['phone_number']) && ($contact['phone_number'] === $phone || $contact['phone_number'] === ltrim($phone, '+'))) {
                    return $contact['id'];
                }
            }
        }

        // Se não encontrou, cria um novo contato
        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts";
        $data = [
            'inbox_id' => $this->inboxId,
            'name' => "WhatsApp " . $originalPhone,
            'phone_number' => $phone,
            'identifier' => $originalPhone
        ];

        $response = $this->makeRequest('POST', $createEndpoint, $data);

        // Verifica se a resposta contém o payload do contato
        if (isset($response['payload']['contact']['id'])) {
            return $response['payload']['contact']['id'];
        }

        Logger::log('error', 'Failed to create contact', [
            'response' => $response,
            'data' => $data
        ]);

        return null;
    }

    private function getOrCreateConversation($contactId)
    {
        // Busca a contact_inbox existente
        $inboxEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contact_inboxes";
        $params = [
            'contact_id' => $contactId,
            'inbox_id' => $this->inboxId
        ];

        $response = $this->makeRequest('GET', $inboxEndpoint . '?' . http_build_query($params));

        $contactInboxId = null;

        if (!empty($response['payload'])) {
            foreach ($response['payload'] as $contactInbox) {
                if ($contactInbox['contact_id'] == $contactId && $contactInbox['inbox_id'] == $this->inboxId) {
                    $contactInboxId = $contactInbox['id'];
                    break;
                }
            }
        }

        if ($contactInboxId) {
            // Busca a conversa existente
            $conversationsEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
            $params = ['contact_inbox_id' => $contactInboxId];

            $response = $this->makeRequest('GET', $conversationsEndpoint . '?' . http_build_query($params));

            if (!empty($response['data'])) {
                foreach ($response['data'] as $conversation) {
                    // Atualiza o status da conversa para "open"
                    $this->updateConversationStatus($conversation['id'], 'open');
                    return $conversation['id'];
                }
            }
        }

        // Se não encontrou, cria uma nova conversa
        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
        $data = [
            'contact_id' => $contactId,
            'inbox_id' => (int)$this->inboxId,
            'status' => 'open',
            'source_id' => (string)$contactId
        ];

        $response = $this->makeRequest('POST', $createEndpoint, $data);

        if (isset($response['id'])) {
            return $response['id'];
        }

        return null;
    }

    private function updateConversationStatus($conversationId, $status)
    {
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}";
        $data = ['status' => $status];

        return $this->makeRequest('PUT', $endpoint, $data);
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

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        Logger::log('info', 'API Request', [
            'url' => $url,
            'method' => $method,
            'data' => $data,
            'response' => $response,
            'http_code' => $httpCode
        ]);

        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        return null;
    }

    private function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return '+' . $phone;
    }
}
