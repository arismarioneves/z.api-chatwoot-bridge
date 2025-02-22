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
        $this->baseUrl = $_ENV['CHATWOOT_BASE_URL'];
        $this->apiToken = $_ENV['CHATWOOT_API_TOKEN'];
        $this->accountId = $_ENV['CHATWOOT_ACCOUNT_ID'];
        $this->inboxId = $_ENV['CHATWOOT_INBOX_ID'];
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

    private function formatPhoneNumber($phone)
    {
        // Remove qualquer caractere não numérico
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Adiciona o prefixo + se não existir
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        return $phone;
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

        // Depois, vamos criar ou buscar a conversa
        $conversationId = $this->getOrCreateConversation($formattedPhone, $contactId);

        if (!$conversationId) {
            Logger::log('error', 'Failed to create or get conversation');
            return false;
        }

        // Agora vamos enviar a mensagem
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";

        $data = [
            'content' => $message,
            'message_type' => 'incoming'
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
        // Primeiro, tenta buscar o contato existente
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search?q=" . urlencode($phone);
        $searchResponse = $this->makeRequest('GET', $searchEndpoint);

        if (!empty($searchResponse['payload']) && !empty($searchResponse['payload'][0]['id'])) {
            return $searchResponse['payload'][0]['id'];
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

    private function getOrCreateConversation($phone, $contactId)
    {
        $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";

        $data = [
            'source_id' => $phone,
            'inbox_id' => (int)$this->inboxId,
            'contact_id' => $contactId,
            'status' => 'pending'
        ];

        $response = $this->makeRequest('POST', $endpoint, $data);

        Logger::log('info', 'Create conversation response', [
            'response' => $response
        ]);

        // Verifica se a resposta contém o ID da conversa
        if (isset($response['id'])) {
            return $response['id'];
        } elseif (isset($response['contact_inbox']['id'])) {
            // Algumas versões do Chatwoot retornam o ID desta forma
            return $response['contact_inbox']['id'];
        }

        return null;
    }

    private function makeRequest($method, $url, $data = null)
    {
        $headers = [
            'Content-Type: application/json',
            'api_access_token: ' . $this->apiToken
        ];

        $ch = curl_init($url);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Adiciona logs de debug
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Log da requisição completa
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        Logger::log('info', 'API Request Details', [
            'url' => $url,
            'method' => $method,
            'data' => $data,
            'http_code' => $httpCode,
            'response' => $response,
            'verbose_log' => $verboseLog
        ]);

        curl_close($ch);

        return json_decode($response, true);
    }
}
