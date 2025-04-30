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

    public function sendMessage($sourceId, $message, $attachments = [], $messageType = 'incoming')
    {
        Logger::log('info', 'Preparing to send message to Chatwoot', [
            'source_id' => $sourceId,
            'message' => $message,
            'has_attachments' => !empty($attachments),
            'message_type' => $messageType
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
        $endpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";

        $data = [
            'content' => $message,
            'message_type' => $messageType, // Usa o tipo de mensagem especificado
            'private' => false
        ];

        // Adiciona anexos se houver
        if (!empty($attachments)) {
            $data['attachments'] = $attachments;
            Logger::log('info', 'Adding attachments to message', [
                'attachments' => $attachments
            ]);
        }

        Logger::log('info', 'Sending message via Chatwoot', [
            'source_id' => $sourceId,
            'endpoint' => $endpoint,
            'data' => $data
        ]);

        return $this->makeRequest('POST', $endpoint, $data);
    }

    private function findOrCreateContact($phone)
    {
        Logger::log('info', 'Finding or creating contact', [
            'phone' => $phone
        ]);

        // Formata o número no formato E.164 (com o + no início)
        $e164Phone = '+' . $phone;

        // Busca mais específica usando o número de telefone formatado
        $searchEndpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/contacts/search";
        $searchResponse = $this->makeRequest('GET', $searchEndpoint . '?q=' . urlencode($e164Phone));

        Logger::log('debug', 'Contact search response', ['response' => $searchResponse]);

        // Verificar explicitamente se o número de telefone corresponde
        if (!empty($searchResponse['payload'])) {
            foreach ($searchResponse['payload'] as $contact) {
                if (
                    isset($contact['phone_number']) &&
                    $this->formatPhoneNumber($contact['phone_number']) === $phone
                ) {
                    Logger::log('info', 'Found contact with exact phone number match', [
                        'contact_id' => $contact['id'],
                        'phone_number' => $contact['phone_number']
                    ]);

                    // Atualiza o perfil do contato existente
                    $this->updateContactProfile($contact['id'], $phone);
                    return $contact['id'];
                }
            }
        }

        // Tenta busca direta por contatos como fallback
        $listEndpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/contacts";
        $listResponse = $this->makeRequest('GET', $listEndpoint);

        Logger::log('debug', 'Contact list response', ['response' => $listResponse]);

        // Busca mais rigorosa por correspondência exata de telefone
        if (!empty($listResponse['payload'])) {
            foreach ($listResponse['payload'] as $contact) {
                // Verifica se o source_id corresponde exatamente ao número
                if (isset($contact['source_id']) && $contact['source_id'] === $phone) {
                    Logger::log('info', 'Found existing contact by source_id', [
                        'contact_id' => $contact['id'],
                        'source_id' => $contact['source_id']
                    ]);

                    // Atualiza o perfil do contato existente
                    $this->updateContactProfile($contact['id'], $phone);
                    return $contact['id'];
                }

                // Verifica se o phone_number corresponde ao número formatado
                if (isset($contact['phone_number'])) {
                    $contactPhone = $this->formatPhoneNumber($contact['phone_number']);
                    if ($contactPhone === $phone) {
                        Logger::log('info', 'Found existing contact by phone_number', [
                            'contact_id' => $contact['id'],
                            'phone_number' => $contact['phone_number'],
                            'formatted_phone' => $contactPhone
                        ]);

                        // Atualiza o perfil do contato existente
                        $this->updateContactProfile($contact['id'], $phone);
                        return $contact['id'];
                    }
                }
            }
        }

        // Se não encontrou, cria novo contato
        $endpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/contacts";

        // Busca informações do perfil no WhatsApp
        $profileInfo = $this->getWhatsAppProfile($phone);

        // Identificador único e consistente para o contato
        $data = [
            'inbox_id' => (int)$this->inboxId,
            'name' => $profileInfo['name'] ?? "WhatsApp: {$phone}",
            'phone_number' => $e164Phone,
            'identifier' => "whatsapp:{$phone}", // Identificador único com prefixo
            'source_id' => $phone,
            'custom_attributes' => [
                'whatsapp_phone' => $phone,
                'whatsapp_identifier' => $phone
            ]
        ];

        if (!empty($profileInfo['avatar_url'])) {
            $data['avatar_url'] = $profileInfo['avatar_url'];
        }

        Logger::log('info', 'Creating new contact with data', [
            'data' => $data
        ]);

        $response = $this->makeRequest('POST', $endpoint, $data);

        Logger::log('debug', 'Create contact response', ['response' => $response]);

        // Verifica se o contato foi criado com sucesso
        // A resposta pode ter diferentes estruturas dependendo da versão da API
        if (isset($response['id'])) {
            // Formato simples: { "id": 123, ... }
            Logger::log('info', 'Successfully created new contact', [
                'contact_id' => $response['id'],
                'name' => $data['name'],
                'phone' => $phone
            ]);
            return $response['id'];
        } elseif (isset($response['payload']) && isset($response['payload']['contact']) && isset($response['payload']['contact']['id'])) {
            // Formato aninhado: { "payload": { "contact": { "id": 123, ... } } }
            $contactId = $response['payload']['contact']['id'];
            Logger::log('info', 'Successfully created new contact (nested response)', [
                'contact_id' => $contactId,
                'name' => $data['name'],
                'phone' => $phone
            ]);
            return $contactId;
        }

        // Verifica se há erro na resposta
        if (isset($response['error'])) {
            Logger::log('error', 'Failed to create contact - API error', [
                'error' => $response['error'],
                'message' => $response['message'] ?? 'No message',
                'data' => $data
            ]);
        } elseif (isset($response['message'])) {
            Logger::log('error', 'Failed to create contact - API message', [
                'message' => $response['message'],
                'attributes' => $response['attributes'] ?? [],
                'data' => $data
            ]);
        } else {
            Logger::log('error', 'Failed to create contact - Unknown error', [
                'response' => $response,
                'data' => $data
            ]);
        }

        // Melhorar a abordagem alternativa com identificador mais específico
        Logger::log('info', 'Trying alternative approach to create contact with minimal data');

        $minimalData = [
            'inbox_id' => (int)$this->inboxId,
            'name' => "WhatsApp: {$phone}",
            'phone_number' => $e164Phone,
            'source_id' => $phone,
            'identifier' => "wa:{$phone}" // Identificador alternativo
        ];

        $retryResponse = $this->makeRequest('POST', $endpoint, $minimalData);
        Logger::log('debug', 'Retry create contact response', ['response' => $retryResponse]);

        // Verifica se o contato foi criado com sucesso (mesma lógica que acima)
        if (isset($retryResponse['id'])) {
            Logger::log('info', 'Successfully created new contact with minimal data', [
                'contact_id' => $retryResponse['id']
            ]);
            return $retryResponse['id'];
        } elseif (isset($retryResponse['payload']) && isset($retryResponse['payload']['contact']) && isset($retryResponse['payload']['contact']['id'])) {
            $contactId = $retryResponse['payload']['contact']['id'];
            Logger::log('info', 'Successfully created new contact with minimal data (nested response)', [
                'contact_id' => $contactId
            ]);
            return $contactId;
        }

        // Se chegou aqui, verifica se o erro é "Phone number has already been taken"
        if (isset($retryResponse['message']) && $retryResponse['message'] === 'Phone number has already been taken') {
            // Tenta buscar o contato recém-criado pelo número de telefone exato
            Logger::log('info', 'Contact already exists, trying to find it by exact phone number');

            // Busca mais precisa por número de telefone
            $searchEndpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/contacts/search";
            $searchResponse = $this->makeRequest('GET', $searchEndpoint . '?q=' . urlencode($e164Phone));

            Logger::log('debug', 'Contact search response after creation attempt', ['response' => $searchResponse]);

            if (!empty($searchResponse['payload'])) {
                foreach ($searchResponse['payload'] as $contact) {
                    // Verifica correspondência exata do número de telefone
                    if (
                        isset($contact['phone_number']) &&
                        ($this->formatPhoneNumber($contact['phone_number']) === $phone ||
                            $contact['phone_number'] === $e164Phone)
                    ) {
                        Logger::log('info', 'Found contact by exact phone match after creation attempt', [
                            'contact_id' => $contact['id'],
                            'phone_number' => $contact['phone_number']
                        ]);
                        return $contact['id'];
                    }
                }
            }
        }

        return null;
    }

    private function findOrCreateConversation($phone, $contactId)
    {
        Logger::log('info', 'Finding or creating conversation', [
            'phone' => $phone,
            'contact_id' => $contactId
        ]);

        // Busca conversa existente
        $searchEndpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/conversations";
        $searchParams = http_build_query([
            'inbox_id' => $this->inboxId,
            'contact_id' => $contactId,
            'status' => 'all',
            'source_id' => $phone
        ]);

        $response = $this->makeRequest('GET', $searchEndpoint . '?' . $searchParams);

        Logger::log('debug', 'Conversation search response', [
            'response' => $response,
            'search_params' => [
                'inbox_id' => $this->inboxId,
                'contact_id' => $contactId
            ]
        ]);

        Logger::log('debug', 'Raw conversation search response', [
            'response' => $response
        ]);

        // Verifica diferentes estruturas de resposta possíveis
        $conversations = null;
        $foundConversation = null; // Variável para armazenar a conversa a ser usada

        // Estrutura 1: { "data": [ ... ] }
        if (!empty($response['data']) && is_array($response['data']) && !isset($response['data']['payload'])) {
            $conversations = $response['data'];
            Logger::log('info', 'Found existing conversations (direct array structure)', [
                'count' => count($conversations)
            ]);
        }
        // Estrutura 2: { "data": { "payload": [ ... ] } }
        else if (!empty($response['data']) && isset($response['data']['payload']) && is_array($response['data']['payload'])) {
            $conversations = $response['data']['payload'];
            Logger::log('info', 'Found existing conversations (nested payload structure)', [
                'count' => count($conversations)
            ]);
        }
        // Estrutura 3: Direct array response (Less common but possible)
        else if (is_array($response) && !empty($response) && isset($response[0]['id'])) {
            $conversations = $response;
            Logger::log('info', 'Found conversations (direct array structure)', [
                'count' => count($conversations)
            ]);
        }

        // Estrutura 3: { "meta": {...}, "id": X, ... } (resposta de conversa única)
        else if (isset($response['id']) && isset($response['meta'])) {
            // É uma única conversa
            $conversations = [$response];
            Logger::log('info', 'Found single conversation', [
                'conversation_id' => $response['id']
            ]);
        }

        if ($conversations && count($conversations) > 0) {
            Logger::log('info', 'Processing found conversations', ['count' => count($conversations)]);

            // Prioriza conversas abertas que pertencem ao contato correto
            foreach ($conversations as $conversation) {
                if (isset($conversation['id']) && isset($conversation['status']) && $conversation['status'] === 'open') {
                    // Verifica se a conversa pertence ao contato correto
                    if (isset($conversation['meta']['sender']['id']) && $conversation['meta']['sender']['id'] == $contactId) {
                        Logger::log('info', 'Using existing open conversation for contact', [
                            'conversation_id' => $conversation['id'],
                            'contact_id_match' => $contactId
                        ]);
                        $foundConversation = $conversation;
                        break; // Encontrou uma aberta, para de procurar
                    }
                }
            }

            // Se não encontrou conversa aberta, usa a primeira conversa encontrada para este contato
            if (!$foundConversation) {
                foreach ($conversations as $conversation) {
                    // Verifica se a conversa pertence ao contato correto
                    if (isset($conversation['id']) && isset($conversation['meta']['sender']['id']) && $conversation['meta']['sender']['id'] == $contactId) {
                        Logger::log('info', 'Using first existing (non-open) conversation found for contact', [
                            'conversation_id' => $conversation['id'],
                            'status' => $conversation['status'] ?? 'unknown',
                            'contact_id_match' => $contactId
                        ]);
                        $foundConversation = $conversation;
                        break; // Encontrou a primeira relevante
                    }
                }
            }

            if ($foundConversation && isset($foundConversation['id'])) {
                return $foundConversation['id'];
            } else {
                Logger::log('warning', 'Found conversations, but none matched the contact ID or structure was unexpected.', [
                    'contact_id' => $contactId,
                    'first_conversation_keys' => !empty($conversations) ? json_encode(array_keys($conversations[0])) : 'empty'
                ]);
                // Prossegue para criar uma nova conversa se nenhuma existente adequada foi confirmada
            }
        } else {
            Logger::log('info', 'No existing conversations found matching search criteria.');
            // Prossegue para criar uma nova conversa
        }

        // Se não encontrar ou não confirmar uma conversa existente, cria uma nova conversa
        Logger::log('info', 'Proceeding to create a new conversation.');

        $createEndpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/conversations";

        $data = [
            'source_id' => 'whatsapp:' . $phone,
            'inbox_id' => (int)$this->inboxId,
            'contact_id' => (int)$contactId,
            'status' => 'open',
            'additional_attributes' => [
                'whatsapp_phone' => $phone,
                'phone_identifier' => $phone
            ]
        ];

        Logger::log('debug', 'Creating conversation with data', ['data' => $data]);

        $response = $this->makeRequest('POST', $createEndpoint, $data);

        Logger::log('debug', 'Create conversation response', ['response' => $response]);

        if (isset($response['id'])) {
            Logger::log('info', 'Successfully created new conversation', [
                'conversation_id' => $response['id']
            ]);
            return $response['id'];
        } elseif (isset($response['payload']) && isset($response['payload']['id'])) { // Outra estrutura possível na criação
            Logger::log('info', 'Successfully created new conversation (payload structure)', [
                'conversation_id' => $response['payload']['id']
            ]);
            return $response['payload']['id'];
        }

        Logger::log('error', 'Failed to create conversation', [
            'response' => $response,
            'data' => $data
        ]);

        return null;
    }

    private function getWhatsAppProfile($phone)
    {
        try {
            $zapi = new ZAPIHandler();
            $profileInfo = $zapi->getProfileInfo($phone);

            if (!$profileInfo || isset($profileInfo['error'])) {
                Logger::log('warning', 'Could not get WhatsApp profile from API, using default values', [
                    'phone' => $phone,
                    'response' => $profileInfo
                ]);

                // Retorna informações básicas quando a API falha
                return [
                    'name' => "WhatsApp: {$phone}",
                    'phone' => $phone
                ];
            }

            return $profileInfo;
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to get WhatsApp profile', ['error' => $e->getMessage()]);

            // Retorna informações básicas em caso de exceção
            return [
                'name' => "WhatsApp: {$phone}",
                'phone' => $phone
            ];
        }
    }

    private function updateContactProfile($contactId, $phone)
    {
        $profileInfo = $this->getWhatsAppProfile($phone);
        if (empty($profileInfo)) return;

        $endpoint = "{$this->baseUrl}api/v1/accounts/{$this->accountId}/contacts/{$contactId}";
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
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        Logger::log('debug', 'Chatwoot Request', [
            'url' => $url,
            'method' => $method,
            'data' => $data
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            Logger::log('error', 'Curl error in makeRequest', [
                'error' => curl_error($ch),
                'url' => $url
            ]);
            curl_close($ch);
            return null;
        }

        // Log da resposta HTTP
        Logger::log('debug', 'Chatwoot Response', [
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'raw_response' => substr($response, 0, 1000) // Limita o tamanho do log
        ]);

        curl_close($ch);

        $decodedResponse = json_decode($response, true);

        // Se o código HTTP não for de sucesso (2xx), registra um erro
        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::log('error', 'HTTP error in Chatwoot request', [
                'url' => $url,
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $decodedResponse
            ]);
        }

        return $decodedResponse;
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
