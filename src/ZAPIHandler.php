<?php

namespace WhatsappBridge;

class ZAPIHandler
{
    private $instanceId;
    private $token;
    private $securityToken;
    private $baseUrl;

    public function __construct()
    {
        $this->instanceId = ZAPI_INSTANCE_ID;
        $this->token = ZAPI_TOKEN;
        $this->securityToken = ZAPI_SECURITY_TOKEN;
        $this->baseUrl = ZAPI_BASE_URL;
    }

    public function sendMessage($phone, $message)
    {
        Logger::log('info', 'Sending message through Z-API', [
            'phone' => $phone,
            'message' => $message
        ]);

        $phone = $this->formatPhoneNumber($phone);

        $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-text";

        $data = [
            'phone' => $phone,
            'message' => $message
        ];

        Logger::log('info', 'Sending message via Z-API', [
            'phone' => $phone,
            'endpoint' => $endpoint
        ]);

        return $this->makeRequest('POST', $endpoint, $data);
    }

    public function getProfileInfo($phone)
    {
        $phone = $this->formatPhoneNumber($phone);

        Logger::log('info', 'Getting WhatsApp profile info', [
            'phone' => $phone
        ]);

        // Tenta obter informações do contato
        try {
            // Endpoint para verificar se o número existe no WhatsApp
            $checkEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/phone-exists";
            $checkResponse = $this->makeRequest('GET', $checkEndpoint, ['phone' => $phone]);

            Logger::log('debug', 'Phone exists check response', ['response' => $checkResponse]);

            // Se o número existe no WhatsApp, tenta obter mais informações
            if ($checkResponse && isset($checkResponse['exists']) && $checkResponse['exists']) {
                // Tenta obter o nome do perfil
                $contactsEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/contacts";
                $contactsResponse = $this->makeRequest('GET', $contactsEndpoint);

                $contactName = $phone;

                // Procura o contato na lista de contatos
                if ($contactsResponse && is_array($contactsResponse)) {
                    foreach ($contactsResponse as $contact) {
                        if (isset($contact['phone']) && $this->formatPhoneNumber($contact['phone']) === $phone) {
                            $contactName = $contact['name'] ?? $contact['phone'] ?? $phone;
                            break;
                        }
                    }
                }

                // Tenta obter a foto do perfil
                $profilePicEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/profile-picture";
                $picResponse = $this->makeRequest('GET', $profilePicEndpoint, ['phone' => $phone]);

                return [
                    'name' => $contactName,
                    'avatar_url' => $picResponse['profileImage'] ?? null,
                    'phone' => $phone
                ];
            }
        } catch (\Exception $e) {
            Logger::log('error', 'Error getting WhatsApp profile info', [
                'error' => $e->getMessage(),
                'phone' => $phone
            ]);
        }

        // Retorna informações básicas se não conseguir obter do WhatsApp
        return [
            'name' => "WhatsApp: {$phone}",
            'phone' => $phone
        ];
    }

    private function makeRequest($method, $url, $data = [])
    {
        $headers = [
            'Content-Type: application/json',
            'Client-Token: ' . $this->securityToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        Logger::log('debug', 'Z-API Request', [
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
