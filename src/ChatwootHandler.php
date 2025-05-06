<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class ChatwootHandler
{
    private string $apiToken;
    private string $accountId;
    private string $inboxId;
    private string $baseUrl;
    private ZAPIHandler $zapi; // Para buscar perfil

    public function __construct()
    {
        // Validação básica das constantes
        if (!defined('CHATWOOT_API_TOKEN') || !defined('CHATWOOT_ACCOUNT_ID') || !defined('CHATWOOT_INBOX_ID') || !defined('CHATWOOT_BASE_URL')) {
            throw new \Exception("Chatwoot configuration constants are not defined.");
        }
        $this->apiToken = CHATWOOT_API_TOKEN;
        $this->accountId = CHATWOOT_ACCOUNT_ID;
        $this->inboxId = CHATWOOT_INBOX_ID;
        $this->baseUrl = rtrim(CHATWOOT_BASE_URL, '/'); // Garante sem barra no final
        $this->zapi = new ZAPIHandler(); // Instancia ZAPI para buscar perfil
    }

    /**
     * Envia mensagem para o Chatwoot, criando contato/conversa se necessário.
     *
     * @param string $sourceId Telefone (sem formatação inicial)
     * @param string $message Texto da mensagem
     * @param array $attachments Anexos no formato esperado pelo Chatwoot: [['url' => '...', 'type' => 'image|audio|video|file'], ...]
     * @param string $messageType 'incoming' ou 'outgoing'
     * @return array|null Resposta da API do Chatwoot ou null em caso de falha crítica.
     */
    public function sendMessage(string $sourceId, string $message, array $attachments = [], string $messageType = 'incoming'): ?array
    {
        $phone = Formatter::formatPhoneNumber($sourceId); // Formata para E.164 sem '+'
        Logger::log('info', 'Preparing to send message to Chatwoot', [
            'phone' => $phone, // Log formatado
            'message_type' => $messageType,
            'attachment_count' => count($attachments)
        ]);

        try {
            // Garante que o contato exista e obtém o ID
            $contactId = $this->findOrCreateContact($phone);
            if (!$contactId) {
                // Erro já logado em findOrCreateContact
                return null;
            }

            // Garante que a conversa exista e obtém o ID
            $conversationId = $this->findOrCreateConversation($phone, $contactId);
            if (!$conversationId) {
                // Erro já logado em findOrCreateConversation
                return null;
            }

            // Monta o payload da mensagem
            $endpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";
            $data = [
                'content' => $message,
                'message_type' => $messageType,
                'private' => false,
            ];

            // Adiciona anexos se houver, garantindo o formato correto
            if (!empty($attachments)) {
                // Chatwoot espera um array de arquivos para upload, não URLs diretas na criação da msg via API
                // Precisamos fazer upload dos anexos primeiro e depois referenciá-los,
                // OU se a API aceitar URLs diretas (menos provável), ajustar aqui.
                // SOLUÇÃO ATUAL: Chatwoot API v1 NÃO suporta anexar URLs diretamente na criação da mensagem.
                // Os anexos de Z-API -> Chatwoot precisam ser baixados e enviados como multipart/form-data.
                // Por simplicidade, vamos apenas logar um aviso por enquanto e enviar a msg sem anexo.
                Logger::log('warning', 'Attachment sending Z-API -> Chatwoot via URL is not directly supported by Chatwoot API v1 messages endpoint. Sending message without attachments.', [
                    'attachments_received' => $attachments
                ]);
                // $data['attachments'] = $this->uploadAttachmentsToChatwoot($attachments); // TODO: Implementar upload real
            }

            Logger::log('info', 'Sending message object to Chatwoot API', [
                'endpoint' => $endpoint,
                'payload_keys' => array_keys($data) // Não logar $data['content'] por padrão (pode ser grande/sensível)
            ]);

            return $this->makeRequest('POST', $endpoint, $data);
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to send message via Chatwoot', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'message_type' => $messageType
            ]);
            return null;
        }
    }

    /** Encontra ou cria um contato no Chatwoot */
    private function findOrCreateContact(string $phone): ?int
    {
        Logger::log('info', 'Finding or creating Chatwoot contact', ['phone' => $phone]);
        $e164Phone = '+' . $phone; // Formato E.164 com '+' para busca/criação

        // 1. Tentar busca por 'identifier' (se configurado para ser o telefone) ou telefone
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search";
        // A busca 'q' pode ser por nome, email, telefone ou identifier
        $searchResponse = $this->makeRequest('GET', $searchEndpoint, ['q' => $phone]); // Busca pelo número sem '+'

        if (!empty($searchResponse['payload'])) {
            foreach ($searchResponse['payload'] as $contact) {
                // Verifica se o telefone ou identifier corresponde EXATAMENTE
                $contactPhone = isset($contact['phone_number']) ? Formatter::formatPhoneNumber($contact['phone_number']) : null;
                $contactIdentifier = $contact['identifier'] ?? null;

                if ($contactPhone === $phone || $contactIdentifier === $phone) {
                    Logger::log('info', 'Found existing Chatwoot contact by phone/identifier search', ['contact_id' => $contact['id']]);
                    // Opcional: Atualizar perfil se necessário (pode ser feito separadamente)
                    // $this->updateContactProfileIfNeeded($contact['id'], $phone);
                    return $contact['id'];
                }
            }
        }

        Logger::log('info', 'Contact not found via search, attempting creation.', ['phone' => $phone]);

        // 2. Se não encontrou, cria o contato
        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts";

        // Busca informações do perfil no WhatsApp para enriquecer
        $profileInfo = $this->zapi->getProfileInfo($phone); // Usa o método do ZAPIHandler

        $data = [
            'inbox_id' => (int)$this->inboxId,
            'name' => $profileInfo['name'] ?? $phone, // Usa nome do Z-API ou telefone
            'phone_number' => $e164Phone,           // Formato E.164 com '+'
            'identifier' => $phone,                 // Identificador único (telefone sem '+')
            // 'source_id' => $phone, // source_id é geralmente associado ao contact_inbox, não ao contato diretamente
            'custom_attributes' => [                // Atributos personalizados podem ser úteis
                'whatsapp_phone' => $phone
            ]
        ];

        if (!empty($profileInfo['avatar_url'])) {
            // Chatwoot pode tentar baixar o avatar se a URL for pública
            $data['avatar_url'] = $profileInfo['avatar_url'];
        }

        Logger::log('debug', 'Attempting to create Chatwoot contact', ['data' => $data]);
        $createResponse = $this->makeRequest('POST', $createEndpoint, $data);

        // Verifica a resposta da criação
        $contactId = $createResponse['payload']['contact']['id'] ?? $createResponse['id'] ?? null;

        if ($contactId) {
            Logger::log('info', 'Successfully created new Chatwoot contact', ['contact_id' => $contactId]);
            return $contactId;
        } else {
            // Verifica se o erro é "Phone number has already been taken" ou "Identifier has already been taken"
            $errorMessage = $createResponse['message'] ?? '';
            $isPhoneTaken = str_contains($errorMessage, 'Phone number has already been taken');
            $isIdentifierTaken = str_contains($errorMessage, 'Identifier has already been taken');

            if (($isPhoneTaken || $isIdentifierTaken) && isset($createResponse['attributes'])) {
                // O erro indica que já existe, tenta buscar novamente com mais precisão
                Logger::log('warning', 'Contact creation failed (already exists?), retrying search...', [
                    'error_message' => $errorMessage,
                    'attributes' => $createResponse['attributes'] ?? []
                ]);
                // Tenta buscar pelo ID que *pode* estar nos atributos do erro (depende da versão Chatwoot)
                $existingId = $createResponse['attributes']['id'] ?? null;
                if ($existingId) return $existingId;

                // Se não conseguiu ID pelo erro, faz a busca novamente
                sleep(1); // Pequena pausa antes de buscar de novo
                $searchResponse = $this->makeRequest('GET', $searchEndpoint, ['q' => $phone]);
                if (!empty($searchResponse['payload'])) {
                    foreach ($searchResponse['payload'] as $contact) {
                        $contactPhone = isset($contact['phone_number']) ? Formatter::formatPhoneNumber($contact['phone_number']) : null;
                        $contactIdentifier = $contact['identifier'] ?? null;
                        if ($contactPhone === $phone || $contactIdentifier === $phone) {
                            Logger::log('info', 'Found existing Chatwoot contact on second search attempt', ['contact_id' => $contact['id']]);
                            return $contact['id'];
                        }
                    }
                }
            }

            // Se falhou por outro motivo ou a segunda busca não achou
            Logger::log('error', 'Failed to create or definitively find Chatwoot contact', [
                'phone' => $phone,
                'creation_response' => $createResponse
            ]);
            return null;
        }
    }

    /** Encontra ou cria uma conversa no Chatwoot */
    private function findOrCreateConversation(string $phone, int $contactId): ?int
    {
        Logger::log('info', 'Finding or creating Chatwoot conversation', ['contact_id' => $contactId]);

        // 1. Busca conversas existentes para o contato NA INBOX CORRETA
        $listEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}/conversations";
        $listResponse = $this->makeRequest('GET', $listEndpoint);

        $foundConversationId = null;
        if (!empty($listResponse['payload']) && is_array($listResponse['payload'])) {
            foreach ($listResponse['payload'] as $conversation) {
                // Verifica se a conversa pertence à inbox correta E está aberta (prioridade)
                if (($conversation['inbox_id'] ?? null) == $this->inboxId && ($conversation['status'] ?? '') === 'open') {
                    Logger::log('info', 'Found existing open Chatwoot conversation for contact in the correct inbox', ['conversation_id' => $conversation['id']]);
                    $foundConversationId = $conversation['id'];
                    break; // Encontrou aberta, usa essa
                }
            }
            // Se não achou aberta, pega a primeira da lista que seja da inbox correta (qualquer status)
            if (!$foundConversationId) {
                foreach ($listResponse['payload'] as $conversation) {
                    if (($conversation['inbox_id'] ?? null) == $this->inboxId) {
                        Logger::log('info', 'Found existing non-open Chatwoot conversation for contact in the correct inbox', ['conversation_id' => $conversation['id'], 'status' => $conversation['status'] ?? 'unknown']);
                        $foundConversationId = $conversation['id'];
                        // Poderia reabrir a conversa aqui se necessário:
                        // $this->updateConversationStatus($foundConversationId, 'open');
                        break;
                    }
                }
            }
        }

        if ($foundConversationId) {
            return $foundConversationId;
        }

        Logger::log('info', 'No suitable existing conversation found, attempting creation.', ['contact_id' => $contactId]);

        // 2. Se não encontrou, cria a conversa
        $createEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
        $data = [
            'inbox_id' => (int)$this->inboxId,
            'contact_id' => $contactId,
            // 'source_id' deve ser o identificador único da conversa NO CANAL (neste caso, o telefone)
            // A API parece criar o contact_inbox automaticamente com base no contact_id e inbox_id
            // Mas podemos tentar passar o source_id aqui também.
            'source_id' => $phone,
            'status' => 'open' // Cria a conversa como aberta
            // Adicionar atributos adicionais se relevante
            // 'additional_attributes' => ['whatsapp_conversation' => true]
        ];

        Logger::log('debug', 'Attempting to create Chatwoot conversation', ['data' => $data]);
        $createResponse = $this->makeRequest('POST', $createEndpoint, $data);

        // Verifica a resposta - pode vir em 'id' ou 'payload.id'
        $conversationId = $createResponse['id'] ?? $createResponse['payload']['id'] ?? null;

        if ($conversationId) {
            Logger::log('info', 'Successfully created new Chatwoot conversation', ['conversation_id' => $conversationId]);
            return $conversationId;
        } else {
            Logger::log('error', 'Failed to create Chatwoot conversation', [
                'contact_id' => $contactId,
                'creation_response' => $createResponse
            ]);
            return null;
        }
    }

    // --- Funções de Upload de Anexo (Exemplo/Placeholder) ---
    // TODO: Implementar a lógica real de upload para Chatwoot
    /**
     * Faz upload de anexos para o Chatwoot e retorna dados para linkar à mensagem.
     * NOTA: Esta é uma função placeholder. A implementação real é necessária.
     * @param array $attachments [['url' => '...', 'type' => '...'], ...]
     * @return array Array de IDs de anexo para usar no payload da mensagem, ou array vazio.
     */
    private function uploadAttachmentsToChatwoot(array $attachments): array
    {
        Logger::log('debug', 'Starting attachment upload process for Chatwoot');
        $uploadedAttachmentIds = [];

        foreach ($attachments as $attachment) {
            if (empty($attachment['url'])) continue;

            try {
                // 1. Baixar o arquivo da URL (Z-API)
                $fileContent = file_get_contents($attachment['url']);
                if ($fileContent === false) {
                    Logger::log('error', 'Failed to download attachment from Z-API URL', ['url' => $attachment['url']]);
                    continue;
                }
                $tempFilePath = tempnam(sys_get_temp_dir(), 'chatwoot_upload_');
                file_put_contents($tempFilePath, $fileContent);
                unset($fileContent); // Liberar memória

                // 2. Preparar dados para upload multipart
                $fileName = basename($attachment['url']); // Ou usar um nome mais descritivo se disponível
                $mimeType = mime_content_type($tempFilePath); // Detecta o mime type

                $cFile = new \CURLFile($tempFilePath, $mimeType, $fileName);
                $postData = ['attachment' => $cFile];

                // 3. Fazer a requisição POST multipart para o endpoint de mensagens (sim, envia junto com a mensagem)
                // A API do Chatwoot permite enviar anexo *junto* com a criação da mensagem se for multipart
                // Requer refatorar o makeRequest para suportar multipart OU fazer chamada separada aqui
                // $uploadEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{conversationId}/messages";
                // $uploadResponse = $this->makeMultipartRequest($uploadEndpoint, $postData);

                // --- Simulação ---
                Logger::log('info', '[SIMULATION] Uploading attachment to Chatwoot', [
                    'original_url' => $attachment['url'],
                    'temp_path' => $tempFilePath,
                    'filename' => $fileName,
                    'mime_type' => $mimeType
                ]);
                // Em uma implementação real, obteria o ID do anexo da $uploadResponse
                // $uploadedAttachmentIds[] = $uploadResponse['payload']['attachments'][0]['id'];
                // --- Fim Simulação ---

                unlink($tempFilePath); // Limpa o arquivo temporário

            } catch (\Exception $e) {
                Logger::log('error', 'Failed to process/upload attachment to Chatwoot', [
                    'url' => $attachment['url'],
                    'error' => $e->getMessage()
                ]);
                if (isset($tempFilePath) && file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            }
        }
        return $uploadedAttachmentIds;
    }

    /** Executa a requisição cURL para a API Chatwoot */
    private function makeRequest(string $method, string $url, ?array $data = null, bool $isMultipart = false): ?array
    {
        $headers = [
            'api_access_token: ' . $this->apiToken
            // Content-Type é definido pelo cURL para multipart ou setado abaixo para JSON
        ];
        if (!$isMultipart) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // $data deve ser um array para multipart
                Logger::log('debug', 'Chatwoot Multipart Request Data', ['url' => $url, 'data_keys' => array_keys($data ?? [])]);
            } elseif ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                Logger::log('debug', 'Chatwoot JSON Request Data', ['url' => $url, 'method' => $method, 'data' => $data]);
            }
        } elseif ($method === 'GET' && !empty($data)) {
            // Para GET, $data são query parameters
            $url .= '?' . http_build_query($data);
            Logger::log('debug', 'Chatwoot GET Request', ['url' => $url]);
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno !== CURLE_OK) {
            Logger::log('error', 'Chatwoot cURL Error', [
                'url' => $url,
                'method' => $method,
                'errno' => $curlErrno,
                'error' => $curlError
            ]);
            throw new \Exception("Chatwoot cURL request failed: " . $curlError);
        }

        $decodedResponse = json_decode($response, true);

        Logger::log('debug', 'Chatwoot Response', [
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'response_body' => $decodedResponse ?? $response
        ]);

        // Verifica códigos de erro HTTP
        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::log('error', 'Chatwoot HTTP Error', [
                'url' => $url,
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $decodedResponse ?? $response
            ]);
        }

        return $decodedResponse;
    }
}
