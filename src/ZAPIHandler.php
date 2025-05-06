<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class ZAPIHandler
{
    private string $instanceId;
    private string $token;
    private string $securityToken;
    private string $baseUrl;

    public function __construct()
    {
        // Validação básica das constantes
        if (!defined('ZAPI_INSTANCE_ID') || !defined('ZAPI_TOKEN') || !defined('ZAPI_SECURITY_TOKEN') || !defined('ZAPI_BASE_URL')) {
            throw new \Exception("Z-API configuration constants are not defined.");
        }
        $this->instanceId = ZAPI_INSTANCE_ID;
        $this->token = ZAPI_TOKEN;
        $this->securityToken = ZAPI_SECURITY_TOKEN;
        $this->baseUrl = rtrim(ZAPI_BASE_URL, '/'); // Garante que não haja barra no final
    }

    /** Envia uma mensagem de texto simples */
    public function sendMessage(string $phone, string $message): ?array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        Logger::log('info', 'Sending text message via Z-API', ['phone' => $formatedPhone]);

        $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-text";
        $data = [
            'phone' => $formatedPhone,
            'message' => $message
        ];

        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * Envia uma mensagem de mídia (imagem, vídeo, documento, áudio)
     */
    public function sendMediaMessage(string $phone, string $mediaUrl, ?string $caption = null, string $mediaType = 'file', ?string $filename = null): ?array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        Logger::log('info', 'Sending media message via Z-API', [
            'phone' => $formatedPhone,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'filename' => $filename
        ]);

        // Determina o endpoint e o payload da Z-API baseado no tipo
        // (Exemplo - Verifique a documentação oficial da Z-API para os endpoints corretos e parâmetros)
        $endpoint = null;
        $data = [
            'phone' => $formatedPhone,
            // 'caption' => $caption, // Legenda comum a muitos tipos
        ];

        switch (strtolower($mediaType)) {
            case 'image':
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-image";
                $data['image'] = $mediaUrl;
                if (!empty($caption)) $data['caption'] = $caption;
                break;
            case 'video':
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-video";
                $data['video'] = $mediaUrl;
                if (!empty($caption)) $data['caption'] = $caption;
                break;
            case 'audio':
                // Z-API pode ter /send-audio ou /send-ptt (Verificar documentação)
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-audio";
                $data['audio'] = $mediaUrl;
                // Áudio geralmente não tem legenda no WhatsApp
                break;
            case 'document':
            case 'file':
            default: // Assume documento/arquivo como padrão
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-document/{urlencode(pathinfo($mediaUrl, PATHINFO_EXTENSION) ?: 'pdf')}"; // Extensão é obrigatória no endpoint Z-API
                $data['document'] = $mediaUrl;
                $data['fileName'] = $filename ?? basename($mediaUrl); // Usa nome do arquivo ou extrai da URL
                if (!empty($caption)) $data['caption'] = $caption;
                break;
        }

        if (!$endpoint) {
            Logger::log('error', 'Could not determine Z-API endpoint for media type', ['media_type' => $mediaType]);
            return null;
        }

        return $this->makeRequest('POST', $endpoint, $data);
    }

    /** Obtém informações do perfil do WhatsApp (nome, foto) */
    public function getProfileInfo(string $phone): array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        Logger::log('info', 'Getting WhatsApp profile info via Z-API', ['phone' => $formatedPhone]);

        $defaultProfile = ['name' => $formatedPhone, 'phone' => $formatedPhone, 'avatar_url' => null];

        try {
            // 1. Verifica se o número existe (endpoint pode variar)
            // $checkEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/phone-exists/{$formatedPhone}"; // Ou via query param
            // $checkResponse = $this->makeRequest('GET', $checkEndpoint);
            // if (!$checkResponse || !($checkResponse['exists'] ?? false)) {
            //    Logger::log('info', 'Phone does not seem to exist on WhatsApp', ['phone' => $formatedPhone, 'response' => $checkResponse]);
            //    return $defaultProfile;
            // }

            // 2. Tenta obter nome do contato (se salvo na agenda da instância Z-API)
            // Este endpoint lista contatos salvos, não necessariamente o nome público do WhatsApp
            // $contactsEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/contacts";
            // $contactsResponse = $this->makeRequest('GET', $contactsEndpoint);
            // $contactName = $formatedPhone; // Default
            // if ($contactsResponse && is_array($contactsResponse)) {
            //     foreach ($contactsResponse as $contact) {
            //         if (isset($contact['phone']) && Formatter::formatPhoneNumber($contact['phone']) === $formatedPhone) {
            //             $contactName = $contact['name'] ?? $contact['pushName'] ?? $formatedPhone;
            //             break;
            //         }
            //     }
            // }

            // 3. Tenta obter nome público e foto do perfil (Endpoint mais provável)
            $profileEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/get-contact-info/{$formatedPhone}";
            $profileResponse = $this->makeRequest('GET', $profileEndpoint);

            if ($profileResponse && !isset($profileResponse['error'])) {
                Logger::log('debug', 'Z-API get-contact-info response', ['response' => $profileResponse]);
                // Mapeia os campos da resposta da Z-API para o formato esperado
                // (Verificar nomes exatos na documentação Z-API: name, pushName, profilePictureUrl, etc.)
                return [
                    'name' => $profileResponse['name'] ?? $profileResponse['pushName'] ?? $formatedPhone,
                    'avatar_url' => $profileResponse['profilePictureUrl'] ?? $profileResponse['profileImage'] ?? null,
                    'phone' => $formatedPhone
                ];
            } else {
                Logger::log('warning', 'Failed to get profile info from Z-API or API returned error', [
                    'phone' => $formatedPhone,
                    'response' => $profileResponse
                ]);
                // Tentar buscar apenas a foto como fallback?
                $profilePicEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/profile-picture";
                $picResponse = $this->makeRequest('GET', $profilePicEndpoint, ['phone' => $formatedPhone]);
                $defaultProfile['avatar_url'] = $picResponse['profileImage'] ?? $picResponse['url'] ?? null; // Verifica campos comuns
                return $defaultProfile;
            }
        } catch (\Exception $e) {
            Logger::log('error', 'Error getting WhatsApp profile info from Z-API', [
                'error' => $e->getMessage(),
                'phone' => $formatedPhone
            ]);
            return $defaultProfile; // Retorna default em caso de exceção
        }
    }

    /** Executa a requisição cURL para a API Z-API */
    private function makeRequest(string $method, string $url, ?array $data = null): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'Client-Token: ' . $this->securityToken // Header correto para o token de segurança
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // Timeout para conexão e execução
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 segundos para conectar
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 segundos para a requisição completa

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                Logger::log('debug', 'Z-API POST Request Data', ['url' => $url, 'data' => $data]);
            }
        } elseif ($method === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
            Logger::log('debug', 'Z-API GET Request', ['url' => $url]);
        } else {
            // Adicionar suporte a PUT/DELETE se necessário
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                Logger::log('debug', 'Z-API Custom Request Data', ['url' => $url, 'method' => $method, 'data' => $data]);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno !== CURLE_OK) {
            Logger::log('error', 'Z-API cURL Error', [
                'url' => $url,
                'method' => $method,
                'errno' => $curlErrno,
                'error' => $curlError
            ]);
            // Lança exceção em caso de erro de cURL para ser tratada acima
            throw new \Exception("Z-API cURL request failed: " . $curlError);
        }

        $decodedResponse = json_decode($response, true);

        Logger::log('debug', 'Z-API Response', [
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'response_body' => $decodedResponse ?? $response // Loga raw se não for json
        ]);

        // Verifica códigos de erro HTTP comuns da Z-API
        if ($httpCode >= 400) {
            Logger::log('error', 'Z-API HTTP Error', [
                'url' => $url,
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $decodedResponse ?? $response
            ]);
        }

        return $decodedResponse; // Retorna o array decodificado ou null se falhar
    }
}
